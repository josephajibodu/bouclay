<?php

namespace App\Actions\Invoicing;

use App\Actions\Webhooks\EmitInvoicePaymentFailed;
use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Team;
use App\Services\Invoicing\ClassifyPaymentFailure;
use App\Services\Nomba\NombaCheckout;
use App\Services\Nomba\NombaModeResolver;
use Illuminate\Support\Str;

/**
 * Charge a stored payment method against an invoice — the real Nomba
 * server-to-server call ("Charge a Tokenized Card",
 * `/v1/checkout/tokenized-card-payment`) that replaces the Phase 5 simulated
 * Always records a {@see Payment} charge attempt against the invoice.
 * succeeded or failed — schema.md §7 records every attempt, not just
 * successes, since Bouclay runs its own dunning.
 */
class ChargeInvoice
{
    public function __construct(
        private readonly NombaCheckout $checkout,
        private readonly NombaModeResolver $modeResolver,
        private readonly ClassifyPaymentFailure $classifyFailure,
        private readonly EmitInvoicePaymentFailed $emitInvoicePaymentFailed,
    ) {
        //
    }

    public function handle(Team $team, Invoice $invoice, PaymentMethod $paymentMethod, int $attemptNumber = 1): Payment
    {
        $connection = $team->processorConnection;

        // Charge in the same Nomba environment the token was minted in —
        // never assume "whatever mode is active now" (Phase 4 handoff #4).
        $mode = isset($paymentMethod->custom_data['mode'])
            ? ApiKeyMode::tryFrom((string) $paymentMethod->custom_data['mode'])
            : null;
        $mode ??= $this->modeResolver->forConnection($connection);

        $orderReference = (string) Str::uuid();
        $idempotencyKey = hash('sha256', "invoice:{$invoice->id}:attempt:{$attemptNumber}");

        if ($connection === null || $mode === null) {
            return $this->recordFailure($invoice, $paymentMethod, $orderReference, $idempotencyKey, $attemptNumber, 'Nomba is not connected for this team.');
        }

        $invoice->loadMissing('customer');

        try {
            $result = $this->checkout->chargeTokenizedCard($connection, $mode, [
                'orderReference' => $orderReference,
                'customerId' => $invoice->customer->public_id,
                'customerEmail' => $invoice->customer->email,
                'amount' => number_format($invoice->total / 100, 2, '.', ''),
                'currency' => $invoice->currency,
                'callbackUrl' => route('webhooks.nomba.receive', $connection->inbound_webhook_token),
            ], $paymentMethod->processor_token);

            // Nomba's own guidance: never trust the synchronous response
            // alone — confirm via the verify-transactions endpoint before
            // granting value.
            $approved = $result['approved']
                && $this->checkout->verifyOrderSucceeded($connection, $mode, $orderReference);
        } catch (NombaConnectionException $e) {
            return $this->recordFailure($invoice, $paymentMethod, $orderReference, $idempotencyKey, $attemptNumber, $this->friendlyError($e));
        }

        if (! $approved) {
            return $this->recordFailure($invoice, $paymentMethod, $orderReference, $idempotencyKey, $attemptNumber, $result['message']);
        }

        $payment = $invoice->payments()->create([
            'team_id' => $team->id,
            'customer_id' => $invoice->customer_id,
            'payment_method_id' => $paymentMethod->id,
            'processor' => PaymentProcessor::Nomba,
            'processor_reference' => $orderReference,
            'amount' => $invoice->total,
            'currency' => $invoice->currency,
            'status' => PaymentStatus::Succeeded,
            'attempt_number' => $attemptNumber,
            'idempotency_key' => $idempotencyKey,
            'processed_at' => now(),
        ]);

        $invoice->markPaid($payment);

        return $payment;
    }

    private function recordFailure(
        Invoice $invoice,
        PaymentMethod $paymentMethod,
        string $orderReference,
        string $idempotencyKey,
        int $attemptNumber,
        string $reason,
    ): Payment {
        $classification = $this->classifyFailure->classify($reason);

        $payment = $invoice->payments()->create([
            'team_id' => $invoice->team_id,
            'customer_id' => $invoice->customer_id,
            'payment_method_id' => $paymentMethod->id,
            'processor' => PaymentProcessor::Nomba,
            'processor_reference' => $orderReference,
            'amount' => $invoice->total,
            'currency' => $invoice->currency,
            'status' => PaymentStatus::Failed,
            'failure_code' => $classification['code'],
            'failure_reason' => $reason,
            'attempt_number' => $attemptNumber,
            'idempotency_key' => $idempotencyKey,
        ]);

        $invoice->recordFailedAttempt();

        $this->emitInvoicePaymentFailed->handle($invoice, $payment);

        return $payment;
    }

    private function friendlyError(NombaConnectionException $e): string
    {
        return match ($e->reason) {
            'unreachable' => 'Nomba isn’t responding right now.',
            'invalid_credentials' => 'Nomba credentials were rejected.',
            default => 'The charge could not be completed.',
        };
    }
}
