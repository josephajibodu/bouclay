<?php

namespace App\Services\Gateways;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;
use Illuminate\Http\Request;

/**
 * An in-memory gateway that exercises the whole {@see PaymentGateway} contract
 * with no network — checkout → token → charge → webhook → refund — and so the
 * proof the abstraction isn't Nomba-shaped in disguise (IMPLEMENTATION_V2
 * §V2-4). Its credential manifest, wire vocabulary, and signature scheme are
 * deliberately nothing like Nomba's: anything that only works because a driver
 * looks like Nomba fails here.
 *
 * Tests register it via `GatewayManager::extend('nomba', FakeGateway::class)`
 * (or a dedicated key) and drive its behaviour through the public flags below.
 */
class FakeGateway implements PaymentGateway
{
    /** The signature header value this fake accepts as genuine. */
    public const string SIGNATURE = 'fake-signature';

    /** Whether the next charge is approved. */
    public static bool $approveCharges = true;

    /** Whether the next refund succeeds. */
    public static bool $approveRefunds = true;

    /** Whether the next credential verification passes. */
    public static bool $approveCredentials = true;

    /** The token `resolveToken()` hands back, or null for "no token yet". */
    public static ?string $resolvableToken = 'fake-token';

    /** The account id `identifiesConnection()` recognises as its own. */
    public static string $merchantRef = 'fake-merchant';

    /**
     * Recorded charge calls, for assertions.
     *
     * @var list<array<string, mixed>>
     */
    public static array $charges = [];

    /**
     * Recorded refund calls, for assertions.
     *
     * @var list<array<string, mixed>>
     */
    public static array $refunds = [];

    /**
     * Recorded checkout calls, for assertions.
     *
     * @var list<array<string, mixed>>
     */
    public static array $checkouts = [];

    /**
     * Tokens revoked through the driver, for assertions.
     *
     * @var list<string>
     */
    public static array $revoked = [];

    /**
     * Reset all fake state — call in a test's setup.
     */
    public static function reset(): void
    {
        self::$approveCharges = true;
        self::$approveRefunds = true;
        self::$approveCredentials = true;
        self::$resolvableToken = 'fake-token';
        self::$merchantRef = 'fake-merchant';
        self::$charges = [];
        self::$refunds = [];
        self::$checkouts = [];
        self::$revoked = [];
    }

    /**
     * A webhook payload in this fake's own wire format — nothing like Nomba's,
     * so a settlement path that reads it correctly is reading the normalized
     * event rather than one processor's shape.
     *
     * @return array<string, mixed>
     */
    public static function webhookPayload(string $orderReference, bool $succeeded = true, ?string $tokenKey = null): array
    {
        return [
            'kind' => $succeeded ? 'charge.ok' : 'charge.declined',
            'ref' => $orderReference,
            'card' => $tokenKey === null ? null : [
                'token' => $tokenKey,
                'network' => 'fakecard',
                'tail' => '4242',
                'expires' => ['month' => 11, 'year' => 2031],
            ],
            'decline_note' => $succeeded ? null : 'Declined by fake gateway.',
        ];
    }

    public function processor(): PaymentProcessor
    {
        return PaymentProcessor::Nomba;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(['NGN', 'USD'], true, true, true);
    }

    public function configSchema(): GatewayConfigSchema
    {
        return new GatewayConfigSchema(
            label: 'Fake Gateway',
            fields: [
                new GatewayConfigField(key: 'api_key', label: 'API key', secret: true),
                new GatewayConfigField(key: 'merchant_ref', label: 'Merchant reference', required: false),
            ],
        );
    }

    public function verifyCredentials(ApiKeyMode $mode, array $credentials): void
    {
        if (! self::$approveCredentials) {
            throw GatewayException::invalidCredentials('Fake Gateway', 'the fake gateway was told to reject this');
        }
    }

    public function createCheckout(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, bool $tokenizeCard = true): array
    {
        self::$checkouts[] = ['order' => $order, 'tokenizeCard' => $tokenizeCard, 'mode' => $mode->value];

        $orderReference = (string) ($order['orderReference'] ?? 'fake-order-'.count(self::$checkouts));

        return [
            'checkoutLink' => 'https://fake-gateway.test/checkout/'.$orderReference,
            'orderReference' => $orderReference,
        ];
    }

    public function chargeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, string $tokenKey): array
    {
        self::$charges[] = ['order' => $order, 'tokenKey' => $tokenKey, 'mode' => $mode->value];

        return [
            'approved' => self::$approveCharges,
            'message' => self::$approveCharges ? 'Approved by fake gateway.' : 'Declined by fake gateway.',
        ];
    }

    public function verifyCharge(TeamProcessorConnection $connection, ApiKeyMode $mode, string $reference): bool
    {
        return self::$approveCharges;
    }

    public function refund(TeamProcessorConnection $connection, ApiKeyMode $mode, string $chargeReference, int $amountMinor, string $currency): array
    {
        self::$refunds[] = ['reference' => $chargeReference, 'amount' => $amountMinor, 'currency' => $currency];

        return [
            'success' => self::$approveRefunds,
            'reference' => self::$approveRefunds ? 'fake-refund-'.count(self::$refunds) : null,
            'message' => self::$approveRefunds ? 'Refunded by fake gateway.' : 'Refund declined.',
        ];
    }

    public function resolveToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $customerEmail, string $orderReference): ?array
    {
        if (self::$resolvableToken === null) {
            return null;
        }

        return [
            'tokenKey' => self::$resolvableToken,
            'brand' => 'fakecard',
            'last4' => '4242',
            'expiry' => '11/31',
        ];
    }

    public function revokeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $tokenKey): void
    {
        self::$revoked[] = $tokenKey;
    }

    public function verifyWebhookSignature(TeamProcessorConnection $connection, ApiKeyMode $mode, Request $request): bool
    {
        return $request->header('x-fake-signature') === self::SIGNATURE;
    }

    public function identifiesConnection(TeamProcessorConnection $connection, array $payload): bool
    {
        return isset($payload['merchant']) && $payload['merchant'] === self::$merchantRef;
    }

    public function parseWebhookEvent(array $payload): ?GatewayWebhookEvent
    {
        $type = match ((string) ($payload['kind'] ?? '')) {
            'charge.ok' => GatewayWebhookEventType::PaymentSucceeded,
            'charge.declined' => GatewayWebhookEventType::PaymentFailed,
            default => null,
        };

        $reference = $payload['ref'] ?? null;

        if ($type === null || ! is_string($reference) || $reference === '') {
            return null;
        }

        $card = is_array($payload['card'] ?? null) ? $payload['card'] : null;

        return new GatewayWebhookEvent(
            type: $type,
            orderReference: $reference,
            token: $card === null || empty($card['token']) ? null : [
                'tokenKey' => (string) $card['token'],
                'brand' => $card['network'] ?? null,
                'last4' => $card['tail'] ?? null,
                'tokenExpiryMonth' => $card['expires']['month'] ?? null,
                'tokenExpiryYear' => $card['expires']['year'] ?? null,
            ],
            failureReason: is_string($payload['decline_note'] ?? null) ? $payload['decline_note'] : null,
            raw: $payload,
        );
    }
}
