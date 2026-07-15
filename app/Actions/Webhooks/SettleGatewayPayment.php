<?php

namespace App\Actions\Webhooks;

use App\Actions\Invoicing\SettleSubscriptionOnInvoicePayment;
use App\Actions\PaymentMethods\StoreTokenizedPaymentMethod;
use App\Actions\Subscriptions\CreateSubscriptionFromPaymentLinkInvoice;
use App\Enums\ApiKeyMode;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\CheckoutIntents;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayModeResolver;
use App\Services\Gateways\GatewayWebhookEvent;
use Illuminate\Support\Facades\DB;

/**
 * Apply one normalized inbound gateway event to invoice, payment, and
 * subscription state (IMPLEMENTATION_V2 §V2-4).
 *
 * This is the shared settlement path: written once, and reached by every
 * driver, because a {@see GatewayWebhookEvent} has already erased the
 * processor's wire vocabulary. A new gateway teaches Bouclay its payload
 * shape in `parseWebhookEvent()` and settles here for free.
 */
class SettleGatewayPayment
{
    public function __construct(
        private readonly SettleSubscriptionOnInvoicePayment $settleSubscription,
        private readonly StoreTokenizedPaymentMethod $storePaymentMethod,
        private readonly GatewayModeResolver $modeResolver,
        private readonly GatewayManager $gateways,
        private readonly EmitInvoicePaymentFailed $emitInvoicePaymentFailed,
        private readonly CreateSubscriptionFromPaymentLinkInvoice $createPaymentLinkSubscription,
    ) {
        //
    }

    public function handle(TeamProcessorConnection $connection, GatewayWebhookEvent $event): void
    {
        if ($event->isSuccess()) {
            $this->handlePaymentSuccess($connection, $event);

            return;
        }

        $this->handlePaymentFailed($connection, $event);
    }

    private function handlePaymentSuccess(TeamProcessorConnection $connection, GatewayWebhookEvent $event): void
    {
        // Stash the token before anything can bail out: the customer's return
        // leg may be racing this webhook and will look here for the card.
        if ($event->token !== null) {
            CheckoutIntents::putToken($event->orderReference, $event->token);
        }

        $context = $this->resolveCheckoutContext($connection, $event->orderReference);

        if ($context === null) {
            return;
        }

        DB::transaction(function () use ($connection, $event, $context): void {
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->with(['customer', 'subscription', 'team'])
                ->find($context['invoice_id']);

            if (! $invoice instanceof Invoice) {
                return;
            }

            if ($invoice->status === InvoiceStatus::Paid) {
                $payment = $invoice->payments()
                    ->where('status', PaymentStatus::Succeeded)
                    ->latest('id')
                    ->first();

                $payment?->loadMissing('paymentMethod');
                $this->createPaymentLinkSubscription->handle($invoice, $payment?->paymentMethod);
                $this->settleSubscription->onPaymentSucceeded($invoice);

                return;
            }

            if ($invoice->status !== InvoiceStatus::Open) {
                return;
            }

            $existingPayment = Payment::query()
                ->where('processor_reference', $event->orderReference)
                ->lockForUpdate()
                ->first();

            if ($existingPayment?->status === PaymentStatus::Succeeded) {
                $existingPayment->loadMissing('paymentMethod');
                $this->createPaymentLinkSubscription->handle($invoice, $existingPayment->paymentMethod);
                $invoice->refresh();
                $invoice->markPaid($existingPayment);
                $this->settleSubscription->onPaymentSucceeded($invoice);

                return;
            }

            $mode = ApiKeyMode::from($context['mode']);
            $paymentMethod = $this->resolvePaymentMethod(
                $invoice,
                $event,
                $this->processor($connection),
                $mode,
                (bool) $context['tokenize_card'],
                (bool) $context['set_default'],
            );

            if ($existingPayment instanceof Payment) {
                $existingPayment->forceFill([
                    'status' => PaymentStatus::Succeeded,
                    'failure_code' => null,
                    'failure_reason' => null,
                    'payment_method_id' => $paymentMethod?->id ?? $existingPayment->payment_method_id,
                    'raw_response' => $event->raw,
                    'processed_at' => now(),
                ])->save();

                $payment = $existingPayment;
            } else {
                $payment = $invoice->payments()->create([
                    'team_id' => $invoice->team_id,
                    'customer_id' => $invoice->customer_id,
                    'payment_method_id' => $paymentMethod?->id,
                    'processor' => $this->processor($connection),
                    'processor_reference' => $event->orderReference,
                    'amount' => $invoice->total,
                    'currency' => $invoice->currency,
                    'status' => PaymentStatus::Succeeded,
                    'attempt_number' => $invoice->payments()->count() + 1,
                    'idempotency_key' => hash('sha256', "invoice:{$invoice->id}:webhook:{$event->orderReference}"),
                    'raw_response' => $event->raw,
                    'processed_at' => now(),
                ]);
            }

            $this->createPaymentLinkSubscription->handle($invoice, $paymentMethod);
            $invoice->refresh();
            $this->attachPaymentMethodToSubscription($invoice, $paymentMethod);
            $invoice->markPaid($payment);
            $this->settleSubscription->onPaymentSucceeded($invoice);
            CheckoutIntents::clear($event->orderReference);
        });
    }

    private function handlePaymentFailed(TeamProcessorConnection $connection, GatewayWebhookEvent $event): void
    {
        $context = $this->resolveCheckoutContext($connection, $event->orderReference);

        if ($context === null) {
            return;
        }

        DB::transaction(function () use ($connection, $event, $context): void {
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->with(['subscription'])
                ->find($context['invoice_id']);

            if (! $invoice instanceof Invoice || $invoice->status !== InvoiceStatus::Open) {
                return;
            }

            $reason = $event->failureReason ?? 'Payment failed.';
            $failureCode = $this->gateways->forConnection($connection)->classifyDecline($reason);

            $existingPayment = Payment::query()
                ->where('processor_reference', $event->orderReference)
                ->lockForUpdate()
                ->first();

            if ($existingPayment?->status === PaymentStatus::Succeeded) {
                return;
            }

            if ($existingPayment instanceof Payment) {
                if ($existingPayment->status !== PaymentStatus::Failed) {
                    $existingPayment->forceFill([
                        'status' => PaymentStatus::Failed,
                        'failure_code' => $failureCode,
                        'failure_reason' => $reason,
                        'raw_response' => $event->raw,
                    ])->save();
                }

                $payment = $existingPayment;
            } else {
                $payment = $invoice->payments()->create([
                    'team_id' => $invoice->team_id,
                    'customer_id' => $invoice->customer_id,
                    'processor' => $this->processor($connection),
                    'processor_reference' => $event->orderReference,
                    'amount' => $invoice->total,
                    'currency' => $invoice->currency,
                    'status' => PaymentStatus::Failed,
                    'failure_code' => $failureCode,
                    'failure_reason' => $reason,
                    'attempt_number' => $invoice->payments()->count() + 1,
                    'idempotency_key' => hash('sha256', "invoice:{$invoice->id}:webhook-failed:{$event->orderReference}"),
                    'raw_response' => $event->raw,
                ]);
            }

            $invoice->recordFailedAttempt();
            $this->emitInvoicePaymentFailed->handle($invoice, $payment);
            $this->settleSubscription->onAutomaticChargeFailed($invoice);
            CheckoutIntents::clear($event->orderReference);
        });
    }

    private function processor(TeamProcessorConnection $connection): PaymentProcessor
    {
        return PaymentProcessor::from($connection->processor);
    }

    /**
     * What this order was for. A payment row already carrying the reference is
     * the most authoritative answer; then Bouclay's own checkout intent; then
     * the invoice the checkout link was stamped on.
     *
     * @return array{invoice_id: int, mode: string, tokenize_card: bool, set_default: bool}|null
     */
    private function resolveCheckoutContext(TeamProcessorConnection $connection, string $orderReference): ?array
    {
        $payment = Payment::query()
            ->where('processor_reference', $orderReference)
            ->where('team_id', $connection->team_id)
            ->first();

        if ($payment !== null) {
            return [
                'invoice_id' => $payment->invoice_id,
                'mode' => $this->modeResolver->configuredMode()->value,
                'tokenize_card' => false,
                'set_default' => false,
            ];
        }

        $intent = CheckoutIntents::get($orderReference);

        if ($intent !== null && ! empty($intent['invoice_id'])) {
            return [
                'invoice_id' => (int) $intent['invoice_id'],
                'mode' => $this->modeResolver->configuredMode()->value,
                'tokenize_card' => (bool) ($intent['tokenize_card'] ?? false),
                'set_default' => (bool) ($intent['set_default'] ?? false),
            ];
        }

        $invoice = Invoice::query()
            ->where('team_id', $connection->team_id)
            ->where('custom_data->checkout_order_reference', $orderReference)
            ->first();

        if ($invoice === null) {
            return null;
        }

        return [
            'invoice_id' => $invoice->id,
            'mode' => $this->modeResolver->configuredMode()->value,
            'tokenize_card' => true,
            'set_default' => true,
        ];
    }

    private function resolvePaymentMethod(
        Invoice $invoice,
        GatewayWebhookEvent $event,
        PaymentProcessor $processor,
        ApiKeyMode $mode,
        bool $tokenizeCard,
        bool $setDefault,
    ): ?PaymentMethod {
        if (! $tokenizeCard) {
            return null;
        }

        // The event's own token wins; otherwise an earlier webhook for this
        // order may already have stashed one.
        $token = $event->token ?? CheckoutIntents::token($event->orderReference);

        if ($token === null) {
            return null;
        }

        return $this->storePaymentMethod->handle(
            $invoice->customer,
            $processor,
            $token,
            $mode,
            $setDefault,
        );
    }

    private function attachPaymentMethodToSubscription(Invoice $invoice, ?PaymentMethod $paymentMethod): void
    {
        $subscription = $invoice->subscription;

        if ($subscription === null || $paymentMethod === null || $subscription->payment_method_id !== null) {
            return;
        }

        $subscription->update(['payment_method_id' => $paymentMethod->id]);
    }
}
