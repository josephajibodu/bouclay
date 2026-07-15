<?php

namespace App\Actions\Invoicing;

use App\Actions\PaymentMethods\ResolveCheckoutToken;
use App\Actions\PaymentMethods\StoreTokenizedPaymentMethod;
use App\Actions\Subscriptions\CreateSubscriptionFromPaymentLinkInvoice;
use App\Enums\ApiKeyMode;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Team;
use App\Services\Gateways\CheckoutIntents;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * Finish an invoice payment after the customer returns from a gateway's hosted
 * checkout — the return leg, racing the same gateway's webhook. Either may
 * arrive first; both settle idempotently through the invoice's payment rows.
 */
class CompleteHostedCheckoutPayment
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly ResolveCheckoutToken $resolveCheckoutToken,
        private readonly StoreTokenizedPaymentMethod $storePaymentMethod,
        private readonly SettleSubscriptionOnInvoicePayment $settleSubscription,
        private readonly CreateSubscriptionFromPaymentLinkInvoice $createPaymentLinkSubscription,
    ) {
        //
    }

    /**
     * @return array{success: bool, invoice: Invoice|null, message: string}
     */
    public function handle(string $orderReference): array
    {
        $existingPayment = Payment::query()
            ->where('processor_reference', $orderReference)
            ->where('status', PaymentStatus::Succeeded)
            ->first();

        if ($existingPayment !== null) {
            $invoice = Invoice::query()
                ->with(['customer', 'subscription', 'team'])
                ->find($existingPayment->invoice_id);

            if ($invoice instanceof Invoice) {
                $existingPayment->loadMissing('paymentMethod');
                $this->createPaymentLinkSubscription->handle($invoice, $existingPayment->paymentMethod);
                $invoice->refresh();

                if ($invoice->status === InvoiceStatus::Open) {
                    $invoice->markPaid($existingPayment);
                }

                $this->settleSubscription->onPaymentSucceeded($invoice);
                $this->clearCheckoutCaches($orderReference);

                return [
                    'success' => true,
                    'invoice' => $invoice->fresh(['customer', 'lines', 'team']),
                    'message' => 'Payment received — thank you.',
                ];
            }
        }

        $intent = CheckoutIntents::get($orderReference);

        if ($intent === null || empty($intent['invoice_id'])) {
            return [
                'success' => false,
                'invoice' => null,
                'message' => 'We couldn’t match that payment. Please try again.',
            ];
        }

        return DB::transaction(function () use ($orderReference, $intent): array {
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->with(['customer', 'subscription', 'team.processorConnection'])
                ->find($intent['invoice_id']);

            if (! $invoice instanceof Invoice) {
                return [
                    'success' => false,
                    'invoice' => null,
                    'message' => 'We couldn’t match that payment. Please try again.',
                ];
            }

            $idempotencyKey = hash('sha256', "invoice:{$invoice->id}:hosted:{$orderReference}");
            $existingPayment = Payment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingPayment?->status === PaymentStatus::Succeeded) {
                $existingPayment->loadMissing('paymentMethod');
                $this->createPaymentLinkSubscription->handle($invoice, $existingPayment->paymentMethod);
                $invoice->refresh();

                if ($invoice->status === InvoiceStatus::Open) {
                    $invoice->markPaid($existingPayment);
                }

                $this->settleSubscription->onPaymentSucceeded($invoice);
                $this->clearCheckoutCaches($orderReference);

                return [
                    'success' => true,
                    'invoice' => $invoice->fresh(['customer', 'lines', 'team']),
                    'message' => 'Payment received — thank you.',
                ];
            }

            if ($invoice->status !== InvoiceStatus::Open) {
                $this->clearCheckoutCaches($orderReference);

                return [
                    'success' => $invoice->status === InvoiceStatus::Paid,
                    'invoice' => $invoice,
                    'message' => $invoice->status === InvoiceStatus::Paid
                        ? 'Payment received — thank you.'
                        : 'This invoice is no longer open for payment.',
                ];
            }

            $team = $invoice->team;
            $mode = ApiKeyMode::from((string) $intent['mode']);
            $connection = $team->processorConnection;

            try {
                $succeeded = $connection !== null
                    && $this->gateways->forConnection($connection)
                        ->verifyCharge($connection, $mode, $orderReference);
            } catch (GatewayException) {
                $succeeded = false;
            }

            if (! $succeeded) {
                $this->clearCheckoutCaches($orderReference);

                return [
                    'success' => false,
                    'invoice' => $invoice,
                    'message' => 'That payment didn’t go through. Please try again.',
                ];
            }

            $paymentMethod = null;

            if (! empty($intent['tokenize_card'])) {
                $card = $this->resolveCheckoutToken->handle(
                    $connection,
                    $mode,
                    $invoice->customer,
                    $orderReference,
                );

                if ($card !== null) {
                    $paymentMethod = $this->storePaymentMethod->handle(
                        $invoice->customer,
                        PaymentProcessor::from($connection->processor),
                        $card,
                        $mode,
                        (bool) ($intent['set_default'] ?? false),
                    );
                }
            }

            // The charge settled on the connection we just verified it
            // against — that's the processor this payment belongs to.
            $payment = $this->recordPayment(
                $team,
                $invoice,
                $orderReference,
                $paymentMethod,
                $idempotencyKey,
                PaymentProcessor::from($connection->processor),
            );
            $this->createPaymentLinkSubscription->handle($invoice, $paymentMethod);
            $invoice->refresh();
            $this->attachPaymentMethodToSubscription($invoice, $paymentMethod);
            $invoice->markPaid($payment);
            $this->settleSubscription->onPaymentSucceeded($invoice);
            $this->clearCheckoutCaches($orderReference);

            return [
                'success' => true,
                'invoice' => $invoice->fresh(['customer', 'lines', 'team']),
                'message' => 'Payment received — thank you.',
            ];
        });
    }

    private function recordPayment(
        Team $team,
        Invoice $invoice,
        string $orderReference,
        ?PaymentMethod $paymentMethod,
        string $idempotencyKey,
        PaymentProcessor $processor,
    ): Payment {
        return $invoice->payments()->create([
            'team_id' => $team->id,
            'customer_id' => $invoice->customer_id,
            'payment_method_id' => $paymentMethod?->id,
            'processor' => $processor,
            'processor_reference' => $orderReference,
            'amount' => $invoice->total,
            'currency' => $invoice->currency,
            'status' => PaymentStatus::Succeeded,
            'attempt_number' => $invoice->payments()->count() + 1,
            'idempotency_key' => $idempotencyKey,
            'processed_at' => now(),
        ]);
    }

    private function attachPaymentMethodToSubscription(Invoice $invoice, ?PaymentMethod $paymentMethod): void
    {
        $subscription = $invoice->subscription;

        if ($subscription === null || $paymentMethod === null || $subscription->payment_method_id !== null) {
            return;
        }

        $subscription->update(['payment_method_id' => $paymentMethod->id]);
    }

    private function clearCheckoutCaches(string $orderReference): void
    {
        $intent = CheckoutIntents::get($orderReference);

        // An API-initiated session leaves a result behind: the client polls
        // for the outcome, and the intent itself is about to go.
        if ($intent !== null && ! empty($intent['api_checkout_session'])) {
            CheckoutIntents::markCompleted($orderReference, [
                'team_id' => $intent['team_id'],
                'customer_id' => $intent['customer_id'],
                'mode' => $intent['mode'],
            ]);
        }

        CheckoutIntents::clear($orderReference);
    }
}
