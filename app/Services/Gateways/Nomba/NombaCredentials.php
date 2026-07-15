<?php

namespace App\Services\Gateways\Nomba;

use App\Enums\ApiKeyMode;
use App\Models\TeamProcessorConnection;

/**
 * Nomba's credential shape, read out of a connection's encrypted blob.
 *
 * This lives in the driver because the key names and their meaning are
 * Nomba's, not Bouclay's: {@see TeamProcessorConnection} only knows it holds
 * one opaque JSON blob per mode, keyed by whatever that gateway's
 * `configSchema()` manifest declares. Paystack's driver will read its own
 * shape out of the same column without either knowing about the other.
 */
readonly class NombaCredentials
{
    /**
     * @param  string  $accountId  the parent business account — this is what
     *                             authenticates, even when a subaccount is set
     * @param  string|null  $subaccountId  where funds are deposited, if scoped
     * @param  string|null  $webhookSecret  the signing key set on Nomba's dashboard
     */
    public function __construct(
        public string $accountId,
        public ?string $subaccountId,
        public string $clientId,
        public string $clientSecret,
        public ?string $webhookSecret = null,
    ) {}

    /**
     * Read the credentials for a mode, or null when this connection can't
     * authenticate in it.
     */
    public static function fromConnection(TeamProcessorConnection $connection, ApiKeyMode $mode): ?self
    {
        return self::fromBlob($connection->credentialBlobFor($mode));
    }

    /**
     * Read the credentials straight from a submitted or stored blob — used on
     * the connect path, before anything is persisted.
     *
     * @param  array<string, string|null>  $blob
     */
    public static function fromBlob(array $blob): ?self
    {
        $accountId = $blob['account_id'] ?? null;
        $clientId = $blob['client_id'] ?? null;
        $clientSecret = $blob['client_secret'] ?? null;

        if (! $accountId || ! $clientId || ! $clientSecret) {
            return null;
        }

        return new self(
            accountId: $accountId,
            subaccountId: ($blob['subaccount_id'] ?? null) ?: null,
            clientId: $clientId,
            clientSecret: $clientSecret,
            webhookSecret: ($blob['webhook_secret'] ?? null) ?: null,
        );
    }

    /**
     * The account individual business-operation calls are scoped to — the
     * subaccount when one is set, otherwise the same parent account.
     */
    public function requestAccountId(): string
    {
        return $this->subaccountId ?? $this->accountId;
    }
}
