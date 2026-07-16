<?php

namespace Tests\Support\Gateways;

use Illuminate\Http\Request;

/**
 * One gateway's wire format, as a test double (IMPLEMENTATION_V2 §V2-4b).
 *
 * The contract suite states each scenario once and runs it against every
 * driver. It can't share the HTTP fakes, though — no two gateways speak the
 * same format, which is the whole reason the boundary exists. So each driver
 * brings an adapter: the scenario says "make the charge decline", and the
 * adapter knows what that looks like on its own wire.
 *
 * Everything a scenario needs to say is a method here. If a new gateway can't
 * implement one of these, that's a finding about the contract, not about the
 * test.
 */
interface GatewayWire
{
    /**
     * The registry key this driver is registered under.
     */
    public function processor(): string;

    /**
     * A credential blob this driver accepts, keyed by its own manifest.
     *
     * @return array<string, string>
     */
    public function credentials(): array;

    /**
     * Register every endpoint this driver might call, in one go.
     *
     * One method rather than several because `Http::fake()` is
     * first-registered-wins: a second call cannot override the first for the
     * same URL, so a scenario that faked endpoints piecemeal would silently
     * keep the earliest answer.
     *
     * @param  string|null  $tokenKey  the card token a successful charge mints,
     *                                 or null for a gateway that minted none
     */
    public function fakeWire(
        bool $chargeApproved = true,
        bool $settled = true,
        bool $refundAccepted = true,
        string $declineReason = 'Insufficient funds',
        ?string $tokenKey = 'wire-token',
    ): void;

    /**
     * A genuinely signed inbound webhook, as this gateway would send it.
     *
     * Signed rather than stubbed on purpose: a driver that accepts an
     * unsigned event should fail this suite.
     */
    public function signedWebhook(
        string $reference,
        bool $succeeded = true,
        ?string $tokenKey = 'wire-token',
        string $declineReason = 'Insufficient funds',
    ): Request;

    /**
     * The checkout link this gateway's fake returns.
     */
    public function checkoutLink(): string;
}
