<?php

namespace App\Actions\Invoicing;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayModeResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reverse a (possibly partial) amount of a settled payment, through the
 * gateway that minted its token (schema.md §8, IMPLEMENTATION_V2 §V2-4).
 *
 * Writes a {@see Refund} row (the auditable record — amount, reason, gateway
 * ref) rather than overwriting the charge; only when the payment is fully
 * reversed does the source `payments.status` become `refunded`. The gateway's
 * `capabilities()` gate a partial refund — a driver that can't do partials
 * is caught here, never mid-flight.
 */
class RefundPayment
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly GatewayModeResolver $modeResolver,
    ) {
        //
    }

    public function handle(Payment $payment, int $amountMinor, ?string $reason = null): Refund
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            throw new InvalidArgumentException('Only a succeeded payment can be refunded.');
        }

        $alreadyRefunded = (int) $payment->refunds()
            ->where('status', RefundStatus::Succeeded)
            ->sum('amount');
        $refundable = $payment->amount - $alreadyRefunded;

        if ($amountMinor <= 0 || $amountMinor > $refundable) {
            throw new InvalidArgumentException(
                "Refund amount must be between 1 and {$refundable} minor units."
            );
        }

        $gateway = $this->gateways->forPaymentMethod($payment->paymentMethod
            ?? throw new InvalidArgumentException('The payment has no stored card to refund through.'));

        $isPartial = $amountMinor < $payment->amount;
        $capabilities = $gateway->capabilities();

        if (! $capabilities->refunds || ($isPartial && ! $capabilities->partialRefunds)) {
            throw new InvalidArgumentException(
                $payment->processor->label().' does not support this refund.'
            );
        }

        $connection = $payment->team->processorConnections()
            ->where('processor', $payment->processor->value)
            ->first();

        if ($connection === null) {
            throw new InvalidArgumentException($payment->processor->label().' is not connected for this team.');
        }

        $mode = isset($payment->paymentMethod->custom_data['mode'])
            ? ApiKeyMode::tryFrom((string) $payment->paymentMethod->custom_data['mode'])
            : null;
        $mode ??= $this->modeResolver->forConnection($connection);

        if ($mode === null) {
            throw new InvalidArgumentException($payment->processor->label().' is not connected for this mode.');
        }

        try {
            $result = $gateway->refund($connection, $mode, $payment->processor_reference ?? '', $amountMinor, $payment->currency);
        } catch (GatewayException) {
            throw new InvalidArgumentException('The refund could not be reached — try again shortly.');
        }

        return DB::transaction(function () use ($payment, $amountMinor, $reason, $result, $alreadyRefunded): Refund {
            $refund = $payment->refunds()->create([
                'team_id' => $payment->team_id,
                'invoice_id' => $payment->invoice_id,
                'amount' => $amountMinor,
                'currency' => $payment->currency,
                'reason' => $reason,
                'status' => $result['success'] ? RefundStatus::Succeeded : RefundStatus::Failed,
                'processor_reference' => $result['reference'],
            ]);

            // The source charge flips to `refunded` only once fully reversed
            // (schema.md §8) — a partial refund leaves it `succeeded`.
            if ($result['success'] && ($alreadyRefunded + $amountMinor) >= $payment->amount) {
                $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
            }

            return $refund;
        });
    }
}
