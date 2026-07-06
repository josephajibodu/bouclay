<?php

namespace App\Services\Nomba;

use App\Enums\ApiKeyMode;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Models\Customer;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolve tokenised card data for a checkout order — webhook stash first,
 * email-keyed list as fallback (CUSTOMERS_DESIGN §10.3).
 */
class ResolveNombaTokenizedCard
{
    public function __construct(private readonly NombaCheckout $checkout)
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
        $fromWebhook = Cache::get("nomba_token:{$orderReference}");

        if (is_array($fromWebhook) && ! empty($fromWebhook['tokenKey'])) {
            return $fromWebhook;
        }

        if ($connection === null) {
            return null;
        }

        try {
            $cards = $this->checkout->listTokenizedCards($connection, $mode, $customer->email);
        } catch (NombaConnectionException) {
            return null;
        }

        $latest = collect($cards)->last();

        if (! is_array($latest) || empty($latest['tokenKey'])) {
            return null;
        }

        return [
            'tokenKey' => $latest['tokenKey'],
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
