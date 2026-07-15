<?php

namespace App\Actions\PaymentMethods;

use App\Enums\ApiKeyMode;
use App\Models\PaymentMethod;
use App\Models\Team;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort revoke of a card token on the gateway that minted it, so
 * removing a payment method in Bouclay removes it upstream too.
 *
 * Best-effort is deliberate: a processor that's down must not block a customer
 * from removing their card locally. The failure is logged, not surfaced.
 */
class RevokePaymentMethodToken
{
    public function __construct(private readonly GatewayManager $gateways)
    {
        //
    }

    public function handle(Team $team, PaymentMethod $paymentMethod): void
    {
        // Tokens are gateway-bound (schema.md routing rule): revoke through
        // the card's own processor, not whichever gateway the team defaults to.
        $connection = $team->processorConnections()
            ->where('processor', $paymentMethod->processor->value)
            ->first();

        if ($connection === null || ! $this->gateways->has($paymentMethod->processor->value)) {
            return;
        }

        // A token is revoked in the same environment it was minted in. The
        // mode is stashed on the row's custom_data at capture time
        // (CUSTOMERS_DESIGN §10.7 — no schema change); default to test.
        $mode = ($paymentMethod->custom_data['mode'] ?? 'test') === 'live'
            ? ApiKeyMode::Live
            : ApiKeyMode::Test;

        try {
            $this->gateways
                ->forPaymentMethod($paymentMethod)
                ->revokeToken($connection, $mode, $paymentMethod->processor_token);
        } catch (GatewayException $e) {
            Log::warning('Failed to revoke gateway token on payment method removal', [
                'payment_method_id' => $paymentMethod->id,
                'processor' => $paymentMethod->processor->value,
                'reason' => $e->reason,
            ]);
        }
    }
}
