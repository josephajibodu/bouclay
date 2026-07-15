<?php

namespace App\Services\Gateways\Nomba;

use App\Actions\PaymentMethods\ResolveCheckoutToken;
use App\Enums\ApiKeyMode;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayException;

/**
 * Ask Nomba for the card token minted by a checkout — the synchronous
 * fallback for when the webhook carrying it hasn't arrived yet
 * (CUSTOMERS_DESIGN §10.3). Nomba only exposes tokens keyed by customer
 * email, so the latest card for that email is the best available answer.
 *
 * The webhook stash is checked before this, by the shared
 * {@see ResolveCheckoutToken} — that cache is
 * Bouclay's own plumbing and stays out of the driver.
 */
class ResolveNombaTokenizedCard
{
    public function __construct(private readonly NombaCheckout $checkout)
    {
        //
    }

    /**
     * @return array{tokenKey: string, brand: string|null, last4: string|null, expiry: string|null}|null
     */
    public function handle(
        TeamProcessorConnection $connection,
        ApiKeyMode $mode,
        string $customerEmail,
    ): ?array {
        try {
            $cards = $this->checkout->listTokenizedCards($connection, $mode, $customerEmail);
        } catch (GatewayException) {
            return null;
        }

        $latest = collect($cards)->last();

        if (! is_array($latest) || empty($latest['tokenKey'])) {
            return null;
        }

        return [
            'tokenKey' => (string) $latest['tokenKey'],
            'brand' => $latest['cardType'] ?? null,
            'last4' => $this->last4($latest['cardPan'] ?? null),
            'expiry' => $latest['tokenExpirationDate'] ?? null,
        ];
    }

    private function last4(?string $pan): ?string
    {
        if ($pan === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $pan) ?? '';

        return $digits === '' ? null : substr($digits, -4);
    }
}
