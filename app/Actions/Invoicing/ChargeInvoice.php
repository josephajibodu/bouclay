<?php

namespace App\Actions\Invoicing;

use App\Actions\Webhooks\EmitInvoicePaymentFailed;
use App\Enums\ApiKeyMode;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Team;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayModeResolver;
use App\Services\Invoicing\ClassifyPaymentFailure;
use Illuminate\Support\Str;

/**
 * Charge a stored payment method against an invoice — a server-to-server
 * "charge a tokenized card" call routed through the {@see GatewayManager}
 * driver that minted the token (schema.md routing rule: tokens are
 * gateway-bound, so the card's own processor charges it, never the team
 * default). Always records a {@see Payment} attempt against the invoice,
 * succeeded or failed — schema.md §8 records every attempt, not just
 * successes, since Bouclay runs its own dunning.
 */
class ChargeInvoice
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly GatewayModeResolver $modeResolver,
        private readonly ClassifyPaymentFailure $classifyFailure,
        private readonly EmitInvoicePaymentFailed $emitInvoicePaymentFailed,
    ) {
        //
    }

    public function handle(Team $team, Invoice $invoice, PaymentMethod $paymentMethod, int $attemptNumber = 1): Payment
    {
        // Tokens are gateway-bound: charge through the card's own processor's
        // connection, not the team default (schema.md routing rule).
        $connection = $team->processorConnections()
            ->where('processor', $paymentMethod->processor->value)
            ->first();

        // Charge in the same environment the token was minted in — never
        // assume "whatever mode is active now" (Phase 4 handoff #4).
        $mode = isset($paymentMethod->custom_data['mode'])
            ? ApiKeyMode::tryFrom((string) $paymentMethod->custom_data['mode'])
            : null;
        $mode ??= $this->modeResolver->forConnection($connection);

        $orderReference = (string) Str::uuid();
        $idempotencyKey = hash('sha256', "invoice:{$invoice->id}:attempt:{$attemptNumber}");

        if ($connection === null || $mode === null) {
            return $this->recordFailure($invoice, $paymentMethod, $orderReference, $idempotencyKey, $attemptNumber, $paymentMethod->processor->label().' is not connected for this team.');
        }

        $gateway = $this->gateways->forPaymentMethod($paymentMethod);

        $invoice->loadMissing('customer');

        try {
            $result = $gateway->chargeToken($connection, $mode, [
                'orderReference' => $orderReference,
                'customerId' => $invoice->customer->public_id,
                'customerEmail' => $invoice->customer->email,
                'amount' => number_format($invoice->total / 100, 2, '.', ''),
                'currency' => $invoice->currency,
                'callbackUrl' => route('webhooks.gateway.receive', [
                    'processor' => $paymentMethod->processor->value,
                    'token' => $connection->inbound_webhook_token,
                ]),
            ], $paymentMethod->processor_token);

            // Never trust the synchronous response alone — confirm via the
            // driver's verify call before granting value.
            $approved = $result['approved']
                && $gateway->verifyCharge($connection, $mode, $orderReference);
        } catch (GatewayException $e) {
            return $this->recordFailure($invoice, $paymentMethod, $orderReference, $idempotencyKey, $attemptNumber, $e->friendlyMessage());
        }

        if (! $approved) {
            return $this->recordFailure($invoice, $paymentMethod, $orderReference, $idempotencyKey, $attemptNumber, $result['message']);
        }

        $payment = $invoice->payments()->create([
            'team_id' => $team->id,
            'customer_id' => $invoice->customer_id,
            'payment_method_id' => $paymentMethod->id,
            'processor' => $paymentMethod->processor,
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
            'processor' => $paymentMethod->processor,
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
}
