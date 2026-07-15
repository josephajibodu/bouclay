<?php

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\PaymentMethod;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\FakeGateway;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\Nomba\NombaGateway;

/*
|--------------------------------------------------------------------------
| Gateway abstraction contract (IMPLEMENTATION_V2 §V2-4)
|--------------------------------------------------------------------------
|
| The manager resolves a driver by processor, tokens route by
| payment_methods.processor, and the FakeGateway exercises the full money
| contract (charge → verify → refund) with no network — the proof the
| abstraction isn't Nomba-shaped in disguise.
*/

beforeEach(fn () => FakeGateway::reset());

it('resolves the Nomba driver by processor', function () {
    $manager = app(GatewayManager::class);

    expect($manager->driver(PaymentProcessor::Nomba))->toBeInstanceOf(NombaGateway::class)
        ->and($manager->driver('nomba'))->toBeInstanceOf(NombaGateway::class);
});

it('routes a stored card to the gateway that minted its token', function () {
    $manager = app(GatewayManager::class);
    $card = PaymentMethod::factory()->make(['processor' => PaymentProcessor::Nomba]);

    expect($manager->forPaymentMethod($card))->toBeInstanceOf(NombaGateway::class);
});

it('rejects an unregistered processor', function () {
    expect(fn () => app(GatewayManager::class)->driver('paystack'))
        ->toThrow(InvalidArgumentException::class);
});

it('runs the full charge → verify → refund contract against a registered driver', function () {
    $manager = app(GatewayManager::class);
    $manager->extend('nomba', FakeGateway::class);

    $connection = TeamProcessorConnection::factory()->make();
    $driver = $manager->driver('nomba');

    // Charge succeeds and is verifiable…
    $charge = $driver->chargeToken($connection, ApiKeyMode::Test, ['orderReference' => 'ref-1', 'amount' => '100.00'], 'tok_abc');
    expect($charge['approved'])->toBeTrue()
        ->and($driver->verifyCharge($connection, ApiKeyMode::Test, 'ref-1'))->toBeTrue();

    // …then a refund reverses it, recording the call.
    $refund = $driver->refund($connection, ApiKeyMode::Test, 'ref-1', 5000, 'NGN');
    expect($refund['success'])->toBeTrue()
        ->and($refund['reference'])->not->toBeNull()
        ->and(FakeGateway::$charges)->toHaveCount(1)
        ->and(FakeGateway::$refunds)->toHaveCount(1)
        ->and(FakeGateway::$charges[0]['tokenKey'])->toBe('tok_abc');
});

it('classifies a declined charge through the driver', function () {
    $manager = app(GatewayManager::class);
    $manager->extend('nomba', FakeGateway::class);
    FakeGateway::$approveCharges = false;

    $driver = $manager->driver('nomba');
    $charge = $driver->chargeToken(TeamProcessorConnection::factory()->make(), ApiKeyMode::Test, [], 'tok');

    expect($charge['approved'])->toBeFalse();
});

it('advertises Nomba capabilities honestly', function () {
    $caps = app(GatewayManager::class)->driver('nomba')->capabilities();

    expect($caps->supportsCurrency('NGN'))->toBeTrue()
        ->and($caps->supportsCurrency('USD'))->toBeFalse()
        ->and($caps->refunds)->toBeTrue()
        ->and($caps->partialRefunds)->toBeTrue();
});
