<?php

namespace App\Actions\PaymentMethods;

use App\Enums\ApiKeyMode;
use App\Models\Customer;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\CheckoutIntents;
use App\Services\Gateways\GatewayManager;

/**
 * Find the card token a completed checkout minted: the webhook stash first
 * (Bouclay's own record, already normalized by the driver that parsed it),
 * then the driver's synchronous lookup as fallback for when the webhook hasn't
 * landed yet.
 *
 * Written once for every gateway — the driver only supplies the lookup, never
 * the ordering.
 */
class ResolveCheckoutToken
{
    public function __construct(private readonly GatewayManager $gateways)
    {
        //
    }

    /**
     * @return array<string, mixed>|null
     */
    public function handle(
        ?TeamProcessorConnection $connection,
        ApiKeyMode $mode,
        Customer $customer,
        string $orderReference,
    ): ?array {
        $stashed = CheckoutIntents::token($orderReference);

        if ($stashed !== null) {
            return $stashed;
        }

        if ($connection === null) {
            return null;
        }

        return $this->gateways
            ->forConnection($connection)
            ->resolveToken($connection, $mode, $customer->email, $orderReference);
    }
}
