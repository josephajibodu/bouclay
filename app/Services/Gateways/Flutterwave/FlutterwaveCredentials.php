<?php

namespace App\Services\Gateways\Flutterwave;

use App\Enums\ApiKeyMode;
use App\Models\TeamProcessorConnection;

/**
 * Flutterwave's credential shape, read out of a connection's encrypted blob.
 *
 * Lives in the driver: the key names, the `FLWSECK` prefixes, and the fact
 * that webhooks are verified against a hash the merchant chooses (rather than
 * an HMAC of the body) are all Flutterwave's business.
 */
readonly class FlutterwaveCredentials
{
    public function __construct(
        public string $secretKey,
        public ?string $publicKey = null,
        public ?string $encryptionKey = null,
        public ?string $webhookSecretHash = null,
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
            encryptionKey: ($blob['encryption_key'] ?? null) ?: null,
            webhookSecretHash: ($blob['webhook_secret_hash'] ?? null) ?: null,
        );
    }

    /**
     * The prefix Flutterwave issues for a key of this mode. A test key is
     * `FLWSECK_TEST-…` and a live one `FLWSECK-…`, so the two can be told
     * apart before either is used against the wrong environment's data.
     */
    public static function expectedPrefix(ApiKeyMode $mode): string
    {
        return $mode === ApiKeyMode::Test ? 'FLWSECK_TEST-' : 'FLWSECK-';
    }

    public function matchesMode(ApiKeyMode $mode): bool
    {
        return str_starts_with($this->secretKey, self::expectedPrefix($mode));
    }
}
