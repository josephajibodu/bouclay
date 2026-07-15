<?php

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayConfigFieldRole;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayOrder;
use App\Services\Gateways\Paystack\PaystackCredentials;
use App\Services\Gateways\Paystack\PaystackGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Paystack driver (IMPLEMENTATION_V2 §V2-4b)
|--------------------------------------------------------------------------
|
| Everything here is Http::fake'd against Paystack's real wire format. The
| point of a second driver is to prove the V2-4 contract isn't Nomba-shaped,
| so these lean on the places Paystack genuinely differs: integer minor units,
| no separate webhook secret, and a token that only exists on a transaction.
*/

function paystackConnection(string $secretKey = 'sk_test_abc123'): TeamProcessorConnection
{
    return TeamProcessorConnection::factory()->make([
        'processor' => 'paystack',
        'test_credentials' => ['secret_key' => $secretKey, 'public_key' => 'pk_test_abc'],
        'test_connected_at' => now(),
    ]);
}

function paystackDriver(): PaystackGateway
{
    /** @var PaystackGateway $driver */
    $driver = app(GatewayManager::class)->driver('paystack');

    return $driver;
}

function paystackOrder(string $reference = 'ref-1', int $amountMinor = 500000): GatewayOrder
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
 * @param  array<string, mixed>  $authorization
 * @return array<string, mixed>
 */
function paystackTransaction(string $status = 'success', array $authorization = []): array
{
    return [
        'status' => true,
        'data' => [
            'status' => $status,
            'reference' => 'ref-1',
            'gateway_response' => $status === 'success' ? 'Approved' : 'Insufficient funds',
            'authorization' => array_merge([
                'authorization_code' => 'AUTH_abc123',
                'brand' => 'visa',
                'last4' => '4242',
                'exp_month' => '11',
                'exp_year' => '2031',
                'reusable' => true,
            ], $authorization),
        ],
    ];
}

it('is registered and resolves by processor', function () {
    expect(app(GatewayManager::class)->driver('paystack'))->toBeInstanceOf(PaystackGateway::class)
        ->and(paystackDriver()->processor())->toBe(PaymentProcessor::Paystack);
});

it('declares no webhook secret field, because it signs with the secret key', function () {
    $schema = paystackDriver()->configSchema();

    // This is the case the manifest's role concept exists for — the webhooks
    // page must render nothing to fill in rather than demand a secret that
    // Paystack never issues.
    expect($schema->fieldsWithRole(GatewayConfigFieldRole::WebhookSecret))->toBe([])
        ->and(collect($schema->fields)->pluck('key')->all())->toBe(['secret_key', 'public_key'])
        ->and($schema->field('secret_key')->secret)->toBeTrue()
        ->and($schema->field('public_key')->required)->toBeFalse();
});

it('rejects a live key pasted into test mode before it is ever used', function () {
    Http::fake();

    expect(fn () => paystackDriver()->verifyCredentials(ApiKeyMode::Test, ['secret_key' => 'sk_live_oops']))
        ->toThrow(GatewayException::class, 'must start with sk_test_');

    // Nothing was sent — the mistake is caught locally.
    Http::assertNothingSent();
});

it('reports a rejected key as a GatewayException', function () {
    Http::fake(['*/transaction*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

    expect(fn () => paystackDriver()->verifyCredentials(ApiKeyMode::Test, ['secret_key' => 'sk_test_bad']))
        ->toThrow(GatewayException::class);
});

it('sends money as integer minor units, not a formatted string', function () {
    Http::fake(['*/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz', 'reference' => 'ref-1'],
    ])]);

    $checkout = paystackDriver()->createCheckout(paystackConnection(), ApiKeyMode::Test, paystackOrder());

    expect($checkout['checkoutLink'])->toBe('https://checkout.paystack.com/xyz')
        ->and($checkout['orderReference'])->toBe('ref-1');

    Http::assertSent(function ($request) {
        // ₦5,000 is 500000 kobo as an int. Nomba wanted "5000.00"; the shared
        // order carries neither format, so both drivers can be right.
        return $request['amount'] === 500000
            && $request['email'] === 'amina@example.test'
            && $request['channels'] === ['card']
            && $request['reference'] === 'ref-1';
    });
});

it('omits the channel restriction when the order is not card-only', function () {
    Http::fake(['*/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz'],
    ])]);

    $order = new GatewayOrder(
        reference: 'ref-2',
        customerEmail: 'a@test.test',
        amountMinor: 1000,
        currency: 'NGN',
        cardOnly: false,
    );

    paystackDriver()->createCheckout(paystackConnection(), ApiKeyMode::Test, $order);

    Http::assertSent(fn ($request) => ! isset($request['channels']));
});

it('charges a stored authorization code', function () {
    Http::fake(['*/transaction/charge_authorization' => Http::response(paystackTransaction())]);

    $result = paystackDriver()->chargeToken(paystackConnection(), ApiKeyMode::Test, paystackOrder(), 'AUTH_abc123');

    expect($result['approved'])->toBeTrue();

    Http::assertSent(fn ($request) => $request['authorization_code'] === 'AUTH_abc123'
        && $request['amount'] === 500000);
});

it('surfaces the card network response on a decline', function () {
    Http::fake(['*/transaction/charge_authorization' => Http::response(paystackTransaction('failed'))]);

    $result = paystackDriver()->chargeToken(paystackConnection(), ApiKeyMode::Test, paystackOrder(), 'AUTH_abc123');

    // The network's own words are the useful half of a decline — dunning
    // classifies on them.
    expect($result['approved'])->toBeFalse()
        ->and($result['message'])->toBe('Insufficient funds');
});

it('verifies a charge by reference', function () {
    Http::fake(['*/transaction/verify/*' => Http::response(paystackTransaction())]);

    expect(paystackDriver()->verifyCharge(paystackConnection(), ApiKeyMode::Test, 'ref-1'))->toBeTrue();
});

it('does not treat an unsettled transaction as verified', function () {
    Http::fake(['*/transaction/verify/*' => Http::response(paystackTransaction('abandoned'))]);

    expect(paystackDriver()->verifyCharge(paystackConnection(), ApiKeyMode::Test, 'ref-1'))->toBeFalse();
});

it('resolves the token from the transaction, since there is no card list endpoint', function () {
    Http::fake(['*/transaction/verify/*' => Http::response(paystackTransaction())]);

    $token = paystackDriver()->resolveToken(paystackConnection(), ApiKeyMode::Test, 'amina@example.test', 'ref-1');

    expect($token['tokenKey'])->toBe('AUTH_abc123')
        ->and($token['brand'])->toBe('visa')
        ->and($token['last4'])->toBe('4242');
});

it('refuses to store an authorization Paystack marked non-reusable', function () {
    Http::fake(['*/transaction/verify/*' => Http::response(paystackTransaction('success', ['reusable' => false]))]);

    // Storing it would mean a card that fails every renewal for no visible
    // reason.
    expect(paystackDriver()->resolveToken(paystackConnection(), ApiKeyMode::Test, 'a@test.test', 'ref-1'))
        ->toBeNull();
});

it('treats a queued refund as accepted, not failed', function () {
    Http::fake(['*/refund' => Http::response([
        'status' => true,
        'data' => ['id' => 4099, 'status' => 'pending'],
    ])]);

    // Paystack queues refunds; `pending` means accepted and on its way.
    $refund = paystackDriver()->refund(paystackConnection(), ApiKeyMode::Test, 'ref-1', 250000, 'NGN');

    expect($refund['success'])->toBeTrue()
        ->and($refund['reference'])->toBe('4099');

    Http::assertSent(fn ($request) => $request['transaction'] === 'ref-1' && $request['amount'] === 250000);
});

it('verifies a webhook by HMAC-SHA512 over the raw body', function () {
    $connection = paystackConnection('sk_test_signing');
    $payload = ['event' => 'charge.success', 'data' => ['reference' => 'ref-1']];
    $raw = json_encode($payload);

    $request = Request::create('/webhooks/paystack/tok', 'POST', [], [], [], [
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_signing'),
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    expect(paystackDriver()->verifyWebhookSignature($connection, ApiKeyMode::Test, $request))->toBeTrue();
});

it('rejects a webhook signed with the wrong key', function () {
    $raw = json_encode(['event' => 'charge.success']);

    $request = Request::create('/webhooks/paystack/tok', 'POST', [], [], [], [
        'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_someone_else'),
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    expect(paystackDriver()->verifyWebhookSignature(paystackConnection('sk_test_signing'), ApiKeyMode::Test, $request))
        ->toBeFalse();
});

it('normalizes charge.success into the internal event, token and all', function () {
    $event = paystackDriver()->parseWebhookEvent([
        'event' => 'charge.success',
        'data' => paystackTransaction()['data'],
    ]);

    expect($event->isSuccess())->toBeTrue()
        ->and($event->orderReference)->toBe('ref-1')
        ->and($event->token['tokenKey'])->toBe('AUTH_abc123')
        ->and($event->token['last4'])->toBe('4242');
});

it('normalizes charge.failed into a failure event carrying the reason', function () {
    $event = paystackDriver()->parseWebhookEvent([
        'event' => 'charge.failed',
        'data' => ['reference' => 'ref-1', 'gateway_response' => 'Declined'],
    ]);

    expect($event->isSuccess())->toBeFalse()
        ->and($event->failureReason)->toBe('Declined')
        ->and($event->token)->toBeNull();
});

it('ignores Paystack events Bouclay does not act on', function () {
    expect(paystackDriver()->parseWebhookEvent(['event' => 'customeridentification.success', 'data' => []]))
        ->toBeNull();
});

it('advertises its currencies honestly', function () {
    $capabilities = paystackDriver()->capabilities();

    expect($capabilities->supportsCurrency('NGN'))->toBeTrue()
        ->and($capabilities->supportsCurrency('KES'))->toBeTrue()
        // Nomba settles NGN only — a driver that claimed otherwise would let a
        // checkout be created that can never settle.
        ->and($capabilities->supportsCurrency('EUR'))->toBeFalse()
        ->and($capabilities->partialRefunds)->toBeTrue();
});

it('reads its own credential shape and mode prefix', function () {
    $credentials = PaystackCredentials::fromConnection(paystackConnection(), ApiKeyMode::Test);

    expect($credentials->secretKey)->toBe('sk_test_abc123')
        ->and($credentials->publicKey)->toBe('pk_test_abc')
        ->and($credentials->matchesMode(ApiKeyMode::Test))->toBeTrue()
        ->and($credentials->matchesMode(ApiKeyMode::Live))->toBeFalse();
});
