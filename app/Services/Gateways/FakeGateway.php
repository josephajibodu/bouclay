<?php

namespace App\Services\Gateways;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;

/**
 * An in-memory gateway that exercises the {@see PaymentGateway} contract with
 * no network — the proof the abstraction isn't Nomba-shaped in disguise
 * (IMPLEMENTATION_V2 §V2-4). Tests register it via
 * `GatewayManager::extend('nomba', FakeGateway::class)` (or a dedicated key)
 * and drive its behaviour through the public flags below.
 */
class FakeGateway implements PaymentGateway
{
    /** Whether the next charge is approved. */
    public static bool $approveCharges = true;

    /** Whether the next refund succeeds. */
    public static bool $approveRefunds = true;

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
     * Reset all fake state — call in a test's setup.
     */
    public static function reset(): void
    {
        self::$approveCharges = true;
        self::$approveRefunds = true;
        self::$charges = [];
        self::$refunds = [];
    }

    public function processor(): PaymentProcessor
    {
        return PaymentProcessor::Nomba;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(['NGN', 'USD'], true, true, true);
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
}
