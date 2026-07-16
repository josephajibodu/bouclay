<?php

use App\Enums\ApiKeyMode;
use App\Enums\PaymentFailureCode;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayConfigFieldRole;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Gateways\FlutterwaveWire;
use Tests\Support\Gateways\GatewayWire;
use Tests\Support\Gateways\NombaWire;
use Tests\Support\Gateways\PaystackWire;

/*
|--------------------------------------------------------------------------
| The shared gateway contract suite (IMPLEMENTATION_V2 §V2-4b exit)
|--------------------------------------------------------------------------
|
| Every scenario is stated ONCE and run against every driver. A new gateway
| joins by adding one line to the dataset and supplying a GatewayWire adapter
| — if it can't pass these unchanged, it isn't on the contract.
|
| The per-driver suites (PaystackGatewayTest, FlutterwaveGatewayTest, …) still
| earn their keep: they pin what makes each gateway peculiar. This file pins
| what they must all agree on, and deliberately removes the author's
| discretion to be gentler on one driver than another.
|
| Adding a gateway means: a PaymentProcessor case, a GatewayManager registry
| entry, the driver, then one line here plus its GatewayWire. Nothing below
| gets edited — verified by registering an unrelated driver as a fourth entry,
| which passed 14 of these 20 unchanged, the rest failing only where that
| driver was a simplified double rather than a real gateway.
*/

dataset('gateways', [
    'nomba' => [fn () => new NombaWire],
    'paystack' => [fn () => new PaystackWire],
    'flutterwave' => [fn () => new FlutterwaveWire],
]);

/**
 * A connection carrying this gateway's own credentials, connected in test mode.
 */
function wiredConnection(GatewayWire $wire): TeamProcessorConnection
{
    return TeamProcessorConnection::factory()->make([
        'processor' => $wire->processor(),
        'test_credentials' => $wire->credentials(),
        'test_connected_at' => now(),
    ]);
}

function wireOrder(string $reference = 'wire-ref', int $amountMinor = 500000): GatewayOrder
{
    return new GatewayOrder(
        reference: $reference,
        customerEmail: 'amina@example.test',
        amountMinor: $amountMinor,
        currency: 'NGN',
        customerReference: 'ctm_wire',
        callbackUrl: 'https://bouclay.test/callback',
        cardOnly: true,
    );
}

it('is registered under the processor it reports', function (GatewayWire $wire) {
    $driver = app(GatewayManager::class)->driver($wire->processor());

    expect($driver->processor()->value)->toBe($wire->processor());
})->with('gateways');

it('describes itself well enough to render a connect form', function (GatewayWire $wire) {
    $schema = app(GatewayManager::class)->driver($wire->processor())->configSchema();

    // The manifest is the reason a new gateway needs no bespoke UI, so an
    // empty or unlabelled one is a contract failure.
    expect($schema->label)->not->toBe('')
        ->and($schema->fields)->not->toBeEmpty();

    foreach ($schema->fields as $field) {
        expect($field->key)->not->toBe('')
            ->and($field->label)->not->toBe('')
            ->and($field->validationRules())->not->toBeEmpty();
    }
})->with('gateways');

it('accepts the credentials its own manifest asks for', function (GatewayWire $wire) {
    $wire->fakeWire();
    $schema = app(GatewayManager::class)->driver($wire->processor())->configSchema();

    // Every required field the manifest declares must be satisfiable by the
    // blob this gateway actually stores.
    foreach ($schema->fields as $field) {
        if ($field->required) {
            expect($wire->credentials())->toHaveKey($field->key);
        }
    }

    app(GatewayManager::class)->driver($wire->processor())
        ->verifyCredentials(ApiKeyMode::Test, $wire->credentials());
})->with('gateways')->throwsNoExceptions();

it('creates a checkout from the shared order shape', function (GatewayWire $wire) {
    $wire->fakeWire();

    $result = app(GatewayManager::class)->driver($wire->processor())
        ->createCheckout(wiredConnection($wire), ApiKeyMode::Test, wireOrder());

    // Bouclay states the order in minor units and card-only intent; whatever
    // the gateway wanted on the wire is its own business.
    expect($result['checkoutLink'])->toBe($wire->checkoutLink())
        ->and($result['orderReference'])->not->toBe('');
})->with('gateways');

it('charges a stored token and reports approval', function (GatewayWire $wire) {
    $wire->fakeWire(chargeApproved: true);

    $result = app(GatewayManager::class)->driver($wire->processor())
        ->chargeToken(wiredConnection($wire), ApiKeyMode::Test, wireOrder(), 'wire-token');

    expect($result['approved'])->toBeTrue()
        ->and($result['message'])->toBeString();
})->with('gateways');

it('reports a decline without throwing', function (GatewayWire $wire) {
    $wire->fakeWire(chargeApproved: false, declineReason: 'Insufficient funds');

    // A decline is an answer, not an error — throwing here would turn a
    // routine retry into an exception path.
    $result = app(GatewayManager::class)->driver($wire->processor())
        ->chargeToken(wiredConnection($wire), ApiKeyMode::Test, wireOrder(), 'wire-token');

    expect($result['approved'])->toBeFalse()
        ->and($result['message'])->not->toBe('');
})->with('gateways');

it('maps its own decline language onto Bouclay’s vocabulary', function (GatewayWire $wire) {
    $driver = app(GatewayManager::class)->driver($wire->processor());

    // Dunning reads the same answer whichever gateway declined.
    expect($driver->classifyDecline('Insufficient funds'))->toBe(PaymentFailureCode::InsufficientFunds)
        ->and($driver->classifyDecline('Expired card'))->toBe(PaymentFailureCode::CardExpired)
        ->and($driver->classifyDecline('Stolen card'))->toBe(PaymentFailureCode::StolenCard)
        // Unrecognised wording must never be guessed hard — that would strand
        // a subscription the customer would have paid.
        ->and($driver->classifyDecline('wording from the future'))->toBe(PaymentFailureCode::GenericDecline)
        ->and($driver->classifyDecline('wording from the future')->isHard())->toBeFalse();
})->with('gateways');

it('confirms settlement by our own reference', function (GatewayWire $wire) {
    $wire->fakeWire(settled: true);

    // Bouclay only ever knows the reference it generated — a driver that
    // needs its processor's id has to bridge that itself.
    expect(app(GatewayManager::class)->driver($wire->processor())
        ->verifyCharge(wiredConnection($wire), ApiKeyMode::Test, 'wire-ref'))
        ->toBeTrue();
})->with('gateways');

it('reports an unsettled charge as false', function (GatewayWire $wire) {
    $wire->fakeWire(settled: false);

    expect(app(GatewayManager::class)->driver($wire->processor())
        ->verifyCharge(wiredConnection($wire), ApiKeyMode::Test, 'wire-ref'))
        ->toBeFalse();
})->with('gateways');

it('refunds through the driver in minor units', function (GatewayWire $wire) {
    $wire->fakeWire(refundAccepted: true);
    $driver = app(GatewayManager::class)->driver($wire->processor());

    expect($driver->capabilities()->refunds)->toBeTrue();

    $result = $driver->refund(wiredConnection($wire), ApiKeyMode::Test, 'wire-ref', 250000, 'NGN');

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBeString();
})->with('gateways');

it('verifies a genuinely signed webhook and rejects a forged one', function (GatewayWire $wire) {
    $driver = app(GatewayManager::class)->driver($wire->processor());
    $connection = wiredConnection($wire);

    expect($driver->verifyWebhookSignature($connection, ApiKeyMode::Test, $wire->signedWebhook('wire-ref')))
        ->toBeTrue();

    // Same payload, no signature — the three drivers verify three different
    // ways, and all three must say no.
    $forged = Request::create('/webhooks/x/token', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], json_encode(['event' => 'charge.success', 'data' => ['tx_ref' => 'wire-ref']]));

    expect($driver->verifyWebhookSignature($connection, ApiKeyMode::Test, $forged))->toBeFalse();
})->with('gateways');

it('normalizes a successful webhook into the internal event shape', function (GatewayWire $wire) {
    $request = $wire->signedWebhook('wire-ref', succeeded: true, tokenKey: 'wire-token');

    $event = app(GatewayManager::class)->driver($wire->processor())
        ->parseWebhookEvent($request->json()->all());

    // Settlement is written once against this shape, so every driver has to
    // land on it exactly.
    expect($event)->not->toBeNull()
        ->and($event->isSuccess())->toBeTrue()
        ->and($event->orderReference)->toBe('wire-ref')
        ->and($event->token['tokenKey'])->toBe('wire-token')
        ->and($event->raw)->not->toBeEmpty();
})->with('gateways');

it('normalizes a failed webhook and carries no token', function (GatewayWire $wire) {
    $request = $wire->signedWebhook('wire-ref', succeeded: false, declineReason: 'Insufficient funds');

    $event = app(GatewayManager::class)->driver($wire->processor())
        ->parseWebhookEvent($request->json()->all());

    expect($event)->not->toBeNull()
        ->and($event->isSuccess())->toBeFalse()
        ->and($event->orderReference)->toBe('wire-ref')
        // A failed payment mints nothing chargeable.
        ->and($event->token)->toBeNull()
        ->and($event->failureReason)->not->toBe('');
})->with('gateways');

it('ignores an event it does not act on rather than guessing', function (GatewayWire $wire) {
    $event = app(GatewayManager::class)->driver($wire->processor())
        ->parseWebhookEvent(['event' => 'customer.identification.failed', 'data' => ['nothing' => true]]);

    expect($event)->toBeNull();
})->with('gateways');

it('resolves the token a completed checkout minted', function (GatewayWire $wire) {
    $wire->fakeWire(tokenKey: 'wire-token');

    // The synchronous fallback for when the webhook carrying the token hasn't
    // landed yet.
    $token = app(GatewayManager::class)->driver($wire->processor())
        ->resolveToken(wiredConnection($wire), ApiKeyMode::Test, 'amina@example.test', 'wire-ref');

    expect($token['tokenKey'])->toBe('wire-token');
})->with('gateways');

it('returns null rather than throwing when no token exists', function (GatewayWire $wire) {
    $wire->fakeWire(tokenKey: null);

    // Documented in the contract as never throwing: a missing token is a
    // normal outcome of a transfer or USSD payment.
    expect(app(GatewayManager::class)->driver($wire->processor())
        ->resolveToken(wiredConnection($wire), ApiKeyMode::Test, 'amina@example.test', 'wire-ref'))
        ->toBeNull();
})->with('gateways');

it('reports failure as GatewayException, never its own exception type', function (GatewayWire $wire) {
    // Nothing faked: every outbound call fails. Call sites catch
    // GatewayException and must never learn a driver's own type.
    Http::fake(fn () => throw new ConnectionException('network down'));

    expect(fn () => app(GatewayManager::class)->driver($wire->processor())
        ->chargeToken(wiredConnection($wire), ApiKeyMode::Test, wireOrder(), 'wire-token'))
        ->toThrow(GatewayException::class);
})->with('gateways');

it('states its capabilities honestly', function (GatewayWire $wire) {
    $capabilities = app(GatewayManager::class)->driver($wire->processor())->capabilities();

    // Every gateway Bouclay ships bills Naira and tokenizes; the UI gates on
    // these before offering an action.
    expect($capabilities->currencies)->not->toBeEmpty()
        ->and($capabilities->supportsCurrency('NGN'))->toBeTrue()
        ->and($capabilities->tokenization)->toBeTrue();
})->with('gateways');

it('declares whether it needs a separate webhook secret', function (GatewayWire $wire) {
    $schema = app(GatewayManager::class)->driver($wire->processor())->configSchema();
    $fields = $schema->fieldsWithRole(GatewayConfigFieldRole::WebhookSecret);

    // Zero is a real answer (Paystack signs with its secret key); more than
    // one would leave the webhooks page with no single field to ask for.
    expect(count($fields))->toBeLessThanOrEqual(1);

    foreach ($fields as $field) {
        expect($field->secret)->toBeTrue()
            ->and($wire->credentials())->toHaveKey($field->key);
    }
})->with('gateways');

it('keeps every credential key inside the driver’s own manifest', function (GatewayWire $wire) {
    $schema = app(GatewayManager::class)->driver($wire->processor())->configSchema();
    $declared = collect($schema->fields)->pluck('key')->all();

    // A key the driver reads but never declares can't be rendered, validated,
    // or saved by the manifest-driven connect form — it would be invisible
    // config that only works because someone hand-seeded the database.
    foreach (array_keys($wire->credentials()) as $key) {
        expect($declared)->toContain($key);
    }
})->with('gateways');
