<?php

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\Flutterwave\FlutterwaveCredentials;
use App\Services\Gateways\Flutterwave\FlutterwaveGateway;
use App\Services\Gateways\GatewayConfigFieldRole;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Flutterwave driver (IMPLEMENTATION_V2 §V2-4b)
|--------------------------------------------------------------------------
|
| Http::fake'd against Flutterwave's v3 wire format. The cases that earn their
| keep are the ones where it differs from both drivers before it: money as a
| major-unit number, transactions addressed by Flutterwave's id rather than
| ours, and a webhook verified against a shared hash instead of an HMAC.
*/

function flutterwaveConnection(string $secretKey = 'FLWSECK_TEST-abc', ?string $hash = 'whsec_hash_1234'): TeamProcessorConnection
{
    return TeamProcessorConnection::factory()->make([
        'processor' => 'flutterwave',
        'test_credentials' => array_filter([
            'secret_key' => $secretKey,
            'public_key' => 'FLWPUBK_TEST-abc',
            'webhook_secret_hash' => $hash,
        ]),
        'test_connected_at' => now(),
    ]);
}

function flutterwaveDriver(): FlutterwaveGateway
{
    /** @var FlutterwaveGateway $driver */
    $driver = app(GatewayManager::class)->driver('flutterwave');

    return $driver;
}

function flutterwaveOrder(string $reference = 'tx-1', int $amountMinor = 500000): GatewayOrder
{
    return new GatewayOrder(
        reference: $reference,
        customerEmail: 'amina@example.test',
        amountMinor: $amountMinor,
        currency: 'NGN',
        customerReference: 'ctm_1',
        callbackUrl: 'https://bouclay.test/callback',
        cardOnly: true,
    );
}

/**
 * @return array<string, mixed>
 */
function flutterwaveTransaction(string $status = 'successful', bool $withCard = true): array
{
    return [
        'status' => 'success',
        'data' => array_filter([
            'id' => 288200108,
            'tx_ref' => 'tx-1',
            'status' => $status,
            'processor_response' => $status === 'successful' ? 'Approved' : 'Insufficient funds',
            'card' => $withCard ? [
                'token' => 'flw-tok-abc',
                'type' => 'VISA',
                'last_4digits' => '4242',
                'expiry' => '11/31',
            ] : null,
        ]),
    ];
}

it('is registered and resolves by processor', function () {
    expect(app(GatewayManager::class)->driver('flutterwave'))->toBeInstanceOf(FlutterwaveGateway::class)
        ->and(flutterwaveDriver()->processor())->toBe(PaymentProcessor::Flutterwave);
});

it('declares its webhook secret hash as the field that signs events', function () {
    $fields = flutterwaveDriver()->configSchema()->fieldsWithRole(GatewayConfigFieldRole::WebhookSecret);

    // Unlike Paystack, Flutterwave does need a separate secret — the webhooks
    // page learns that from the manifest rather than guessing.
    expect($fields)->toHaveCount(1)
        ->and($fields[0]->key)->toBe('webhook_secret_hash')
        ->and($fields[0]->secret)->toBeTrue()
        ->and(collect(flutterwaveDriver()->configSchema()->fields)->pluck('key')->all())
        ->toBe(['secret_key', 'public_key', 'encryption_key', 'webhook_secret_hash']);
});

it('rejects a live key pasted into test mode before it is ever used', function () {
    Http::fake();

    expect(fn () => flutterwaveDriver()->verifyCredentials(ApiKeyMode::Test, ['secret_key' => 'FLWSECK-live']))
        ->toThrow(GatewayException::class, 'must start with FLWSECK_TEST-');

    Http::assertNothingSent();
});

it('tells a test key apart from a live one despite the shared prefix', function () {
    $test = new FlutterwaveCredentials('FLWSECK_TEST-abc');
    $live = new FlutterwaveCredentials('FLWSECK-abc');

    expect($test->matchesMode(ApiKeyMode::Test))->toBeTrue()
        ->and($test->matchesMode(ApiKeyMode::Live))->toBeFalse()
        ->and($live->matchesMode(ApiKeyMode::Live))->toBeTrue()
        ->and($live->matchesMode(ApiKeyMode::Test))->toBeFalse();
});

it('sends money as a major-unit number', function () {
    Http::fake(['*/v3/payments' => Http::response([
        'status' => 'success',
        'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/xyz'],
    ])]);

    $checkout = flutterwaveDriver()->createCheckout(flutterwaveConnection(), ApiKeyMode::Test, flutterwaveOrder());

    expect($checkout['checkoutLink'])->toBe('https://checkout.flutterwave.com/v3/hosted/pay/xyz')
        ->and($checkout['orderReference'])->toBe('tx-1');

    Http::assertSent(function ($request) {
        // ₦5,000 is 5000 here — a third money format, after Nomba's "5000.00"
        // string and Paystack's 500000 int. The shared order carries none.
        return $request['amount'] === 5000
            && $request['tx_ref'] === 'tx-1'
            && $request['payment_options'] === 'card'
            && $request['customer']['email'] === 'amina@example.test';
    });
});

it('charges a stored card token', function () {
    Http::fake(['*/v3/tokenized-charges' => Http::response(flutterwaveTransaction())]);

    $result = flutterwaveDriver()->chargeToken(flutterwaveConnection(), ApiKeyMode::Test, flutterwaveOrder(), 'flw-tok-abc');

    expect($result['approved'])->toBeTrue();

    Http::assertSent(fn ($request) => $request['token'] === 'flw-tok-abc' && $request['amount'] === 5000);
});

it('surfaces the processor response on a decline', function () {
    Http::fake(['*/v3/tokenized-charges' => Http::response(flutterwaveTransaction('failed'))]);

    $result = flutterwaveDriver()->chargeToken(flutterwaveConnection(), ApiKeyMode::Test, flutterwaveOrder(), 'flw-tok-abc');

    expect($result['approved'])->toBeFalse()
        ->and($result['message'])->toBe('Insufficient funds');
});

it('verifies a charge by our reference, not Flutterwave’s id', function () {
    Http::fake(['*/v3/transactions/verify_by_reference*' => Http::response(flutterwaveTransaction())]);

    // Bouclay never learns Flutterwave's numeric id, so verifying by tx_ref is
    // the only route it can take.
    expect(flutterwaveDriver()->verifyCharge(flutterwaveConnection(), ApiKeyMode::Test, 'tx-1'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'tx_ref=tx-1'));
});

it('treats an unknown reference as not settled rather than an error', function () {
    Http::fake(['*/v3/transactions/verify_by_reference*' => Http::response([
        'status' => 'error',
        'message' => 'No transaction was found for this id',
    ], 404)]);

    expect(flutterwaveDriver()->verifyCharge(flutterwaveConnection(), ApiKeyMode::Test, 'tx-nope'))->toBeFalse();
});

it('resolves our reference into Flutterwave’s id before refunding', function () {
    Http::fake([
        '*/v3/transactions/verify_by_reference*' => Http::response(flutterwaveTransaction()),
        '*/v3/transactions/288200108/refund' => Http::response([
            'status' => 'success',
            'data' => ['id' => 6789, 'status' => 'completed'],
        ]),
    ]);

    $refund = flutterwaveDriver()->refund(flutterwaveConnection(), ApiKeyMode::Test, 'tx-1', 250000, 'NGN');

    expect($refund['success'])->toBeTrue()
        ->and($refund['reference'])->toBe('6789');

    // The round trip is the cost of Flutterwave addressing transactions by its
    // own id, and it's paid inside the driver.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/transactions/288200108/refund')
        && $request['amount'] === 2500);
});

it('treats a queued refund as accepted, not failed', function () {
    Http::fake([
        '*/v3/transactions/verify_by_reference*' => Http::response(flutterwaveTransaction()),
        '*/v3/transactions/288200108/refund' => Http::response([
            'status' => 'success',
            'data' => ['id' => 1, 'status' => 'pending'],
        ]),
    ]);

    expect(flutterwaveDriver()->refund(flutterwaveConnection(), ApiKeyMode::Test, 'tx-1', 1000, 'NGN')['success'])
        ->toBeTrue();
});

it('resolves the token from the transaction', function () {
    Http::fake(['*/v3/transactions/verify_by_reference*' => Http::response(flutterwaveTransaction())]);

    $token = flutterwaveDriver()->resolveToken(flutterwaveConnection(), ApiKeyMode::Test, 'amina@example.test', 'tx-1');

    expect($token['tokenKey'])->toBe('flw-tok-abc')
        ->and($token['brand'])->toBe('VISA')
        ->and($token['last4'])->toBe('4242')
        // Flutterwave reports expiry as one "MM/YY" string; the driver splits it.
        ->and($token['tokenExpiryMonth'])->toBe('11')
        ->and($token['tokenExpiryYear'])->toBe('31');
});

it('returns no token when the charge minted none', function () {
    Http::fake(['*/v3/transactions/verify_by_reference*' => Http::response(flutterwaveTransaction('successful', withCard: false))]);

    expect(flutterwaveDriver()->resolveToken(flutterwaveConnection(), ApiKeyMode::Test, 'a@test.test', 'tx-1'))
        ->toBeNull();
});

it('verifies a webhook against the stored secret hash', function () {
    $request = Request::create('/webhooks/flutterwave/tok', 'POST', [], [], [], [
        'HTTP_VERIF_HASH' => 'whsec_hash_1234',
    ]);

    // A shared secret, not an HMAC of the body — Flutterwave's own scheme.
    expect(flutterwaveDriver()->verifyWebhookSignature(flutterwaveConnection(), ApiKeyMode::Test, $request))
        ->toBeTrue();
});

it('rejects a webhook whose hash does not match', function () {
    $request = Request::create('/webhooks/flutterwave/tok', 'POST', [], [], [], [
        'HTTP_VERIF_HASH' => 'not-the-hash',
    ]);

    expect(flutterwaveDriver()->verifyWebhookSignature(flutterwaveConnection(), ApiKeyMode::Test, $request))
        ->toBeFalse();
});

it('rejects a webhook when no hash is saved', function () {
    $request = Request::create('/webhooks/flutterwave/tok', 'POST', [], [], [], [
        'HTTP_VERIF_HASH' => 'anything',
    ]);

    expect(flutterwaveDriver()->verifyWebhookSignature(flutterwaveConnection(hash: null), ApiKeyMode::Test, $request))
        ->toBeFalse();
});

it('splits one charge.completed event into success and failure by its status', function () {
    $success = flutterwaveDriver()->parseWebhookEvent([
        'event' => 'charge.completed',
        'data' => flutterwaveTransaction()['data'],
    ]);

    expect($success->isSuccess())->toBeTrue()
        ->and($success->orderReference)->toBe('tx-1')
        ->and($success->token['tokenKey'])->toBe('flw-tok-abc');

    // Flutterwave reports both outcomes under one event name — the status
    // inside decides, and the normalized event hides that entirely.
    $failed = flutterwaveDriver()->parseWebhookEvent([
        'event' => 'charge.completed',
        'data' => flutterwaveTransaction('failed')['data'],
    ]);

    expect($failed->isSuccess())->toBeFalse()
        ->and($failed->failureReason)->toBe('Insufficient funds')
        ->and($failed->token)->toBeNull();
});

it('ignores Flutterwave events Bouclay does not act on', function () {
    expect(flutterwaveDriver()->parseWebhookEvent(['event' => 'transfer.completed', 'data' => ['tx_ref' => 'x']]))
        ->toBeNull();
});

it('advertises multi-currency support honestly', function () {
    $capabilities = flutterwaveDriver()->capabilities();

    expect($capabilities->supportsCurrency('NGN'))->toBeTrue()
        ->and($capabilities->supportsCurrency('GBP'))->toBeTrue()
        ->and($capabilities->supportsCurrency('JPY'))->toBeFalse()
        ->and($capabilities->partialRefunds)->toBeTrue();
});
