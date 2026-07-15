<?php

namespace App\Services\Gateways\Paystack;

use App\Enums\ApiKeyMode;
use App\Models\TeamProcessorConnection;

/**
 * Paystack's credential shape, read out of a connection's encrypted blob.
 *
 * Lives in the driver because the key names — and the fact that the secret key
 * doubles as the webhook signing key — are Paystack's business, not Bouclay's.
 */
readonly class PaystackCredentials
{
    public function __construct(
        public string $secretKey,
        public ?string $publicKey = null,
    ) {}

    public static function fromConnection(TeamProcessorConnection $connection, ApiKeyMode $mode): ?self
    {
        return self::fromBlob($connection->credentialBlobFor($mode));
    }

    /**
     * @param  array<string, string|null>  $blob
     */
    public static function fromBlob(array $blob): ?self
    {
        $secretKey = $blob['secret_key'] ?? null;

        if (! $secretKey) {
            return null;
        }

        return new self(
            secretKey: $secretKey,
            publicKey: ($blob['public_key'] ?? null) ?: null,
        );
    }

    /**
     * The prefix Paystack expects for a key of this mode. Getting this wrong
     * is the single most common connect mistake, and it's cheap to catch
     * before a live key is ever used against test data (or worse, the
     * reverse).
     */
    public static function expectedPrefix(ApiKeyMode $mode): string
    {
        return $mode === ApiKeyMode::Test ? 'sk_test_' : 'sk_live_';
    }

    public function matchesMode(ApiKeyMode $mode): bool
    {
        return str_starts_with($this->secretKey, self::expectedPrefix($mode));
    }
}
