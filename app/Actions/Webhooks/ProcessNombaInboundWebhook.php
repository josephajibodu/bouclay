<?php

namespace App\Actions\Webhooks;

use App\Actions\Invoicing\SettleSubscriptionOnInvoicePayment;
use App\Actions\PaymentMethods\StoreTokenizedPaymentMethod;
use App\Enums\ApiKeyMode;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\TeamProcessorConnection;
use App\Services\Invoicing\ClassifyPaymentFailure;
use App\Services\Nomba\NombaModeResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Map signed Nomba inbound events to invoice payments and subscription state.
 */
class ProcessNombaInboundWebhook
{
    public function __construct(
        private readonly SettleSubscriptionOnInvoicePayment $settleSubscription,
        private readonly StoreTokenizedPaymentMethod $storePaymentMethod,
        private readonly NombaModeResolver $modeResolver,
        private readonly ClassifyPaymentFailure $classifyFailure,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TeamProcessorConnection $connection, array $payload): void
    {
        $eventType = (string) ($payload['event_type'] ?? '');

        match ($eventType) {
            'payment_success' => $this->handlePaymentSuccess($connection, $payload),
            'payment_failed' => $this->handlePaymentFailed($connection, $payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePaymentSuccess(TeamProcessorConnection $connection, array $payload): void
    {
        $this->stashTokenizedCard($payload);

        $orderReference = $this->orderReference($payload);

        if ($orderReference === null) {
            return;
        }

        $context = $this->resolveCheckoutContext($connection, $orderReference, $payload);

        if ($context === null) {
            return;
        }

        DB::transaction(function () use ($connection, $payload, $orderReference, $context): void {
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->with(['customer', 'subscription', 'team'])
                ->find($context['invoice_id']);

            if (! $invoice instanceof Invoice) {
                return;
            }

            if ($invoice->status === InvoiceStatus::Paid) {
                $this->settleSubscription->onPaymentSucceeded($invoice);

                return;
            }

            if ($invoice->status !== InvoiceStatus::Open) {
                return;
            }

            $existingPayment = Payment::query()
                ->where('processor_reference', $orderReference)
                ->lockForUpdate()
                ->first();

            if ($existingPayment?->status === PaymentStatus::Succeeded) {
                $invoice->markPaid($existingPayment);
                $this->settleSubscription->onPaymentSucceeded($invoice);

                return;
            }

            $mode = ApiKeyMode::from($context['mode']);
            $paymentMethod = $this->resolvePaymentMethod(
                $connection,
                $invoice,
                $payload,
                $orderReference,
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
                    'raw_response' => $payload,
                    'processed_at' => now(),
                ])->save();

                $payment = $existingPayment;
            } else {
                $payment = $invoice->payments()->create([
                    'team_id' => $invoice->team_id,
                    'customer_id' => $invoice->customer_id,
                    'payment_method_id' => $paymentMethod?->id,
                    'processor' => PaymentProcessor::Nomba,
                    'processor_reference' => $orderReference,
                    'amount' => $invoice->total,
                    'currency' => $invoice->currency,
                    'status' => PaymentStatus::Succeeded,
                    'attempt_number' => $invoice->payments()->count() + 1,
                    'idempotency_key' => hash('sha256', "invoice:{$invoice->id}:webhook:{$orderReference}"),
                    'raw_response' => $payload,
                    'processed_at' => now(),
                ]);
            }

            $invoice->markPaid($payment);
            $this->attachPaymentMethodToSubscription($invoice, $paymentMethod);
            $this->settleSubscription->onPaymentSucceeded($invoice);
            $this->clearCheckoutCaches($orderReference);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handlePaymentFailed(TeamProcessorConnection $connection, array $payload): void
    {
        $orderReference = $this->orderReference($payload);

        if ($orderReference === null) {
            return;
        }

        $context = $this->resolveCheckoutContext($connection, $orderReference, $payload);

        if ($context === null) {
            return;
        }

        DB::transaction(function () use ($payload, $orderReference, $context): void {
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->with(['subscription'])
                ->find($context['invoice_id']);

            if (! $invoice instanceof Invoice || $invoice->status !== InvoiceStatus::Open) {
                return;
            }

            $existingPayment = Payment::query()
                ->where('processor_reference', $orderReference)
                ->lockForUpdate()
                ->first();

            if ($existingPayment?->status === PaymentStatus::Succeeded) {
                return;
            }

            if ($existingPayment instanceof Payment) {
                if ($existingPayment->status !== PaymentStatus::Failed) {
                    $classification = $this->classifyFailure->classify($this->failureReason($payload));

                    $existingPayment->forceFill([
                        'status' => PaymentStatus::Failed,
                        'failure_code' => $classification['code'],
                        'failure_reason' => $this->failureReason($payload),
                        'raw_response' => $payload,
                    ])->save();
                }
            } else {
                $classification = $this->classifyFailure->classify($this->failureReason($payload));

                $invoice->payments()->create([
                    'team_id' => $invoice->team_id,
                    'customer_id' => $invoice->customer_id,
                    'processor' => PaymentProcessor::Nomba,
                    'processor_reference' => $orderReference,
                    'amount' => $invoice->total,
                    'currency' => $invoice->currency,
                    'status' => PaymentStatus::Failed,
                    'failure_code' => $classification['code'],
                    'failure_reason' => $this->failureReason($payload),
                    'attempt_number' => $invoice->payments()->count() + 1,
                    'idempotency_key' => hash('sha256', "invoice:{$invoice->id}:webhook-failed:{$orderReference}"),
                    'raw_response' => $payload,
                ]);
            }

            $invoice->recordFailedAttempt();
            $this->settleSubscription->onAutomaticChargeFailed($invoice);
            $this->clearCheckoutCaches($orderReference);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stashTokenizedCard(array $payload): void
    {
        $tokenized = $payload['data']['tokenizedCardData'] ?? null;
        $order = $payload['data']['order'] ?? null;
        $orderReference = is_array($order) ? ($order['orderReference'] ?? null) : null;

        if (! is_array($tokenized) || empty($tokenized['tokenKey']) || ! is_string($orderReference) || $orderReference === '') {
            return;
        }

        Cache::put("nomba_token:{$orderReference}", [
            'tokenKey' => $tokenized['tokenKey'],
            'brand' => $tokenized['cardType'] ?? ($order['cardType'] ?? null),
            'last4' => $order['cardLast4Digits'] ?? null,
            'tokenExpiryMonth' => $tokenized['tokenExpiryMonth'] ?? null,
            'tokenExpiryYear' => $tokenized['tokenExpiryYear'] ?? null,
        ], now()->addHour());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function orderReference(array $payload): ?string
    {
        $order = $payload['data']['order'] ?? null;

        if (! is_array($order)) {
            return null;
        }

        $orderReference = $order['orderReference'] ?? null;

        return is_string($orderReference) && $orderReference !== '' ? $orderReference : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{invoice_id: int, mode: string, tokenize_card: bool, set_default: bool}|null
     */
    private function resolveCheckoutContext(TeamProcessorConnection $connection, string $orderReference, array $payload): ?array
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

        /** @var array<string, mixed>|null $intent */
        $intent = Cache::get("nomba_checkout:{$orderReference}");

        if (is_array($intent) && ! empty($intent['invoice_id'])) {
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePaymentMethod(
        TeamProcessorConnection $connection,
        Invoice $invoice,
        array $payload,
        string $orderReference,
        ApiKeyMode $mode,
        bool $tokenizeCard,
        bool $setDefault,
    ): ?PaymentMethod {
        if (! $tokenizeCard) {
            return null;
        }

        $tokenized = $payload['data']['tokenizedCardData'] ?? null;
        $order = $payload['data']['order'] ?? null;

        if (! is_array($tokenized) || empty($tokenized['tokenKey'])) {
            $cached = Cache::get("nomba_token:{$orderReference}");

            if (! is_array($cached) || empty($cached['tokenKey'])) {
                return null;
            }

            $tokenized = $cached;
        }

        return $this->storePaymentMethod->handle(
            $invoice->customer,
            [
                'tokenKey' => $tokenized['tokenKey'],
                'brand' => $tokenized['cardType'] ?? (is_array($order) ? ($order['cardType'] ?? null) : null),
                'last4' => is_array($order) ? ($order['cardLast4Digits'] ?? null) : null,
                'tokenExpiryMonth' => $tokenized['tokenExpiryMonth'] ?? null,
                'tokenExpiryYear' => $tokenized['tokenExpiryYear'] ?? null,
            ],
            $mode,
            $setDefault,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function failureReason(array $payload): string
    {
        $transaction = $payload['data']['transaction'] ?? null;

        if (is_array($transaction) && ! empty($transaction['responseMessage'])) {
            return (string) $transaction['responseMessage'];
        }

        return 'Payment failed.';
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
        Cache::forget("nomba_checkout:{$orderReference}");
        Cache::forget("nomba_token:{$orderReference}");
    }
}
