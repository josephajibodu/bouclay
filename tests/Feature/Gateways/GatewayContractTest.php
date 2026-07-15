<?php

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\PaymentMethod;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\FakeGateway;
use App\Services\Gateways\GatewayConfigFieldRole;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\Nomba\NombaCredentials;
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

/*
|--------------------------------------------------------------------------
| The whole contract, end to end (V2-4 exit criterion)
|--------------------------------------------------------------------------
|
| checkout → token → charge → webhook → refund, entirely through the
| FakeGateway. It settles no money and speaks a wire format nothing like
| Nomba's, so anything that only works because a driver looks like Nomba
| fails here.
*/

it('renders a connect form from the driver manifest', function () {
    $schema = app(GatewayManager::class)->driver('nomba')->configSchema();

    expect($schema->label)->toBe('Nomba')
        ->and(collect($schema->fields)->pluck('key')->all())
        ->toBe(['account_id', 'subaccount_id', 'client_id', 'client_secret', 'webhook_secret']);

    // The manifest is what the form and the validator both read.
    expect($schema->validationRules())
        ->toHaveKey('client_secret')
        ->and($schema->validationRules()['subaccount_id'][0])->toBe('nullable')
        ->and($schema->validationRules()['account_id'][0])->toBe('required');

    // A secret is never described in a way that could echo its value.
    $secret = $schema->field('client_secret');
    expect($secret->secret)->toBeTrue()
        ->and($schema->field('account_id')->secret)->toBeFalse();
});

it('keeps unknown keys out of the credential blob', function () {
    $schema = app(GatewayManager::class)->driver('nomba')->configSchema();

    $credentials = $schema->credentialsFrom([
        'account_id' => 'acct-1',
        'client_id' => 'cid',
        'client_secret' => 'shh',
        'webhook_secret' => 'whsec_123',
        'subaccount_id' => '',
        'mode' => 'test',
        'is_admin' => 'yes',
    ]);

    expect($credentials)->toBe([
        'account_id' => 'acct-1',
        'client_id' => 'cid',
        'client_secret' => 'shh',
        'webhook_secret' => 'whsec_123',
    ]);
});

it('runs checkout → token → charge → webhook → refund with no network', function () {
    $manager = app(GatewayManager::class);
    $manager->extend('nomba', FakeGateway::class);

    $driver = $manager->driver('nomba');
    $connection = TeamProcessorConnection::factory()->make();
    $mode = ApiKeyMode::Test;

    // 1. Credentials verify against the driver's own manifest keys.
    $driver->verifyCredentials($mode, ['api_key' => 'sk_fake', 'merchant_ref' => 'm-1']);

    // 2. A hosted checkout is created, tokenizing the card as a byproduct.
    $checkout = $driver->createCheckout($connection, $mode, [
        'orderReference' => 'order-9',
        'customerEmail' => 'amina@example.test',
        'amount' => '5000.00',
        'currency' => 'NGN',
    ], tokenizeCard: true);

    expect($checkout['orderReference'])->toBe('order-9')
        ->and($checkout['checkoutLink'])->toContain('order-9')
        ->and(FakeGateway::$checkouts[0]['tokenizeCard'])->toBeTrue();

    // 3. The token is resolvable synchronously (the webhook-lag fallback).
    $token = $driver->resolveToken($connection, $mode, 'amina@example.test', 'order-9');
    expect($token['tokenKey'])->toBe('fake-token');

    // 4. The gateway's webhook normalizes to Bouclay's internal shape —
    //    including the minted token — despite its own wire vocabulary.
    $event = $driver->parseWebhookEvent(
        FakeGateway::webhookPayload('order-9', succeeded: true, tokenKey: 'fake-token')
    );

    expect($event->isSuccess())->toBeTrue()
        ->and($event->orderReference)->toBe('order-9')
        ->and($event->token['tokenKey'])->toBe('fake-token')
        ->and($event->token['last4'])->toBe('4242');

    // 5. The stored token charges server-to-server, then verifies.
    $charge = $driver->chargeToken($connection, $mode, ['orderReference' => 'renew-1'], $token['tokenKey']);
    expect($charge['approved'])->toBeTrue()
        ->and($driver->verifyCharge($connection, $mode, 'renew-1'))->toBeTrue();

    // 6. And a partial refund reverses part of it.
    $refund = $driver->refund($connection, $mode, 'renew-1', 2500, 'NGN');
    expect($refund['success'])->toBeTrue()
        ->and(FakeGateway::$refunds[0]['amount'])->toBe(2500);

    // 7. Removing the card revokes the token upstream.
    $driver->revokeToken($connection, $mode, $token['tokenKey']);
    expect(FakeGateway::$revoked)->toBe(['fake-token']);
});

it('normalizes a declined webhook into a failure event', function () {
    app(GatewayManager::class)->extend('nomba', FakeGateway::class);

    $event = app(GatewayManager::class)->driver('nomba')->parseWebhookEvent(
        FakeGateway::webhookPayload('order-x', succeeded: false)
    );

    expect($event->isSuccess())->toBeFalse()
        ->and($event->failureReason)->toBe('Declined by fake gateway.')
        ->and($event->token)->toBeNull();
});

it('ignores a webhook event it does not map', function () {
    app(GatewayManager::class)->extend('nomba', FakeGateway::class);

    expect(app(GatewayManager::class)->driver('nomba')->parseWebhookEvent(['kind' => 'payout.settled']))
        ->toBeNull();
});

it('reports rejected credentials as a GatewayException, not a driver-specific type', function () {
    app(GatewayManager::class)->extend('nomba', FakeGateway::class);
    FakeGateway::$approveCredentials = false;

    expect(fn () => app(GatewayManager::class)->driver('nomba')->verifyCredentials(ApiKeyMode::Test, []))
        ->toThrow(GatewayException::class);
});

/*
|--------------------------------------------------------------------------
| Credential shape and webhook secret are driver-owned
|--------------------------------------------------------------------------
|
| The connection model holds an opaque blob; only a driver knows what its keys
| mean. These pin the seam that GatewayBoundaryTest greps for.
*/

it('reads its own credential shape out of the shared blob', function () {
    $connection = TeamProcessorConnection::factory()->make([
        'test_credentials' => [
            'account_id' => 'acct-1',
            'subaccount_id' => 'sub-9',
            'client_id' => 'cid',
            'client_secret' => 'shh',
            'webhook_secret' => 'whsec_x',
        ],
        'test_connected_at' => now(),
    ]);

    $credentials = NombaCredentials::fromConnection($connection, ApiKeyMode::Test);

    expect($credentials->accountId)->toBe('acct-1')
        ->and($credentials->subaccountId)->toBe('sub-9')
        // Auth uses the parent account; business calls scope to the subaccount.
        ->and($credentials->requestAccountId())->toBe('sub-9')
        ->and($credentials->webhookSecret)->toBe('whsec_x');
});

it('falls back to the parent account when no subaccount is set', function () {
    $connection = TeamProcessorConnection::factory()->make([
        'test_credentials' => ['account_id' => 'acct-1', 'client_id' => 'cid', 'client_secret' => 'shh'],
        'test_connected_at' => now(),
    ]);

    expect(NombaCredentials::fromConnection($connection, ApiKeyMode::Test)->requestAccountId())->toBe('acct-1');
});

it('reports incomplete credentials as unusable rather than half-built', function () {
    $connection = TeamProcessorConnection::factory()->make([
        'test_credentials' => ['account_id' => 'acct-1'],
        'test_connected_at' => now(),
    ]);

    expect(NombaCredentials::fromConnection($connection, ApiKeyMode::Test))->toBeNull();
});

it('names the manifest field it verifies webhook signatures with', function () {
    $fields = app(GatewayManager::class)->driver('nomba')->configSchema()
        ->fieldsWithRole(GatewayConfigFieldRole::WebhookSecret);

    // The webhooks page finds the field by role — it never names the key.
    expect($fields)->toHaveCount(1)
        ->and($fields[0]->key)->toBe('webhook_secret')
        ->and($fields[0]->secret)->toBeTrue();
});

it('lets a gateway declare that it needs no separate webhook secret', function () {
    $manager = app(GatewayManager::class);
    $manager->extend('nomba', FakeGateway::class);

    // FakeGateway signs with what it already holds — like Paystack, which
    // HMACs the raw body with its secret key. The webhooks page must be able
    // to learn that and render nothing to fill in.
    expect($manager->driver('nomba')->configSchema()->fieldsWithRole(GatewayConfigFieldRole::WebhookSecret))
        ->toBe([]);
});

it('rejects a webhook when no signing secret is saved', function () {
    $connection = TeamProcessorConnection::factory()->make([
        'test_credentials' => ['account_id' => 'a', 'client_id' => 'c', 'client_secret' => 's'],
        'test_connected_at' => now(),
    ]);

    // An unsigned event is indistinguishable from a forged one.
    expect(app(GatewayManager::class)->driver('nomba')
        ->verifyWebhookSignature($connection, ApiKeyMode::Test, request()))
        ->toBeFalse();
});

it('identifies which connection a tokenless payload came from', function () {
    $driver = app(GatewayManager::class)->driver('nomba');

    $mine = TeamProcessorConnection::factory()->make([
        'test_credentials' => ['account_id' => 'acct-mine', 'client_id' => 'c', 'client_secret' => 's'],
        'test_connected_at' => now(),
    ]);
    $theirs = TeamProcessorConnection::factory()->make([
        'test_credentials' => ['account_id' => 'acct-theirs', 'client_id' => 'c', 'client_secret' => 's'],
        'test_connected_at' => now(),
    ]);

    $payload = ['data' => ['merchant' => ['userId' => 'acct-mine']]];

    expect($driver->identifiesConnection($mine, $payload))->toBeTrue()
        ->and($driver->identifiesConnection($theirs, $payload))->toBeFalse()
        // Nothing to match on is a "no", never a "maybe".
        ->and($driver->identifiesConnection($mine, ['data' => []]))->toBeFalse();
});
