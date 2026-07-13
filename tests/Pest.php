<?php

use App\Actions\Invoicing\ChargeInvoice;
use App\Enums\ApiKeyKind;
use App\Enums\ApiKeyMode;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionStatus;
use App\Models\ApiKey;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Database\Seeders\DemoTeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Attach a user to a team as its owner, holding the team's protected Admin role.
 */
function attachTeamOwner(Team $team, User $user): void
{
    $team->members()->attach($user, [
        'role_id' => $team->roles()->where('name', 'Admin')->firstOrFail()->id,
        'is_owner' => true,
    ]);
}

/**
 * Attach a user to a team as a non-owner member holding the given starter role.
 */
function attachTeamMember(Team $team, User $user, string $role = 'Developer'): void
{
    $team->members()->attach($user, [
        'role_id' => $team->roles()->where('name', $role)->firstOrFail()->id,
        'is_owner' => false,
    ]);
}

/**
 * @return array{team: Team, owner: User, customer: Customer, product: Product, price: Price}
 */
function invoiceFixture(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $product = Product::factory()->for($team)->create(['name' => 'Pro']);
    $price = Price::factory()->for($team)->for($product)->create(['currency' => 'NGN']);
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);

    return compact('team', 'owner', 'customer', 'product', 'price');
}

/**
 * @return array{team: Team, owner: User, customer: Customer, product: Product, price: Price}
 */
function subscriptionFixture(): array
{
    return invoiceFixture();
}

/**
 * The NaijaStream catalog from BILLING_SIMULATIONS.md, built by the same
 * seeder the demo environment uses ({@see DemoTeamSeeder::seedCatalog()}) so
 * the acceptance tests and the seeded catalog can never drift apart.
 *
 * Keys follow the doc's refs: `price_prem_m` (₦5,000/mo Premium, 7-day
 * card-required trial), `price_sports_m` (₦1,500/mo add-on), `price_seat_m`
 * (₦1,000/seat/mo), `welcome20` (20% × 3 intervals on Premium),
 * entitlements `hdStreaming`/`sportsChannels`, and customer `amina`.
 *
 * @return array<string, mixed> team + owner + every catalog object
 */
function naijaStreamFixture(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'NaijaStream', 'default_currency' => 'NGN']);
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $catalog = (new DemoTeamSeeder)->seedCatalog($team);

    return ['team' => $team, 'owner' => $owner, ...$catalog];
}

/**
 * An active, automatic Team seat subscription (price_seat_m, ₦1,000/seat/mo)
 * with a card on file and a clean 30-day cycle starting now — the shared
 * fixture for SIM-02 / SIM-03 / ADV-02..04 mid-cycle proration cases.
 *
 * @return array{fx: array<string, mixed>, subscription: Subscription, item: SubscriptionItem}
 */
function seatSubscription(int $quantity): array
{
    $fx = naijaStreamFixture();
    $team = $fx['team'];

    $card = PaymentMethod::factory()->for($team)->for($fx['amina'])->create(['is_default' => true]);
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    $start = Carbon::now();
    $subscription = Subscription::factory()->for($team)->for($fx['amina'])->create([
        'status' => SubscriptionStatus::Active,
        'currency' => 'NGN',
        'payment_method_id' => $card->id,
        'current_period_start' => $start,
        'current_period_end' => $start->copy()->addDays(30),
    ]);

    $item = SubscriptionItem::factory()->for($subscription)->create([
        'price_id' => $fx['price_seat_m']->id,
        'plan_id' => $fx['teamPlan']->id,
        'product_id' => $fx['naijastream']->id,
        'kind' => SubscriptionItemKind::Plan,
        'quantity' => $quantity,
    ]);

    return ['fx' => $fx, 'subscription' => $subscription, 'item' => $item];
}

/**
 * Fake the real Nomba tokenized-card charge (+ its follow-up verify call)
 * that {@see ChargeInvoice} makes for an automatic
 * subscription/transaction charge — shared by any test exercising a real
 * charge attempt.
 */
function fakeNombaCharge(bool $approved = true): void
{
    Http::fake([
        '*/v1/auth/token/issue' => Http::response(['code' => '00', 'data' => ['access_token' => 'fake-token']]),
        '*/v1/checkout/tokenized-card-payment' => Http::response([
            'code' => '00',
            'data' => ['status' => $approved, 'message' => $approved ? 'Approved by Financial Insitution' : 'Insufficient funds'],
        ]),
        '*/v1/transactions/accounts/single*' => Http::response([
            'code' => '00',
            'data' => ['status' => $approved ? 'SUCCESS' : 'FAILED'],
        ]),
    ]);
}

/**
 * Fake Nomba hosted checkout order creation and verification for invoice
 * collection paths that generate a checkout link.
 */
/**
 * Build a valid Nomba webhook HMAC signature for test payloads.
 *
 * @param  array<string, mixed>  $payload
 */
function nombaWebhookSignature(array $payload, string $secret, string $timestamp): string
{
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $merchant = is_array($data['merchant'] ?? null) ? $data['merchant'] : [];
    $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];

    $responseCode = $transaction['responseCode'] ?? '';
    if ($responseCode === 'null') {
        $responseCode = '';
    }

    $hashingPayload = sprintf(
        '%s:%s:%s:%s:%s:%s:%s:%s:%s',
        (string) ($payload['event_type'] ?? ''),
        (string) ($payload['requestId'] ?? ''),
        (string) ($merchant['userId'] ?? ''),
        (string) ($merchant['walletId'] ?? ''),
        (string) ($transaction['transactionId'] ?? ''),
        (string) ($transaction['type'] ?? ''),
        (string) ($transaction['time'] ?? ''),
        (string) $responseCode,
        $timestamp,
    );

    return base64_encode(hash_hmac('sha256', $hashingPayload, $secret, true));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nombaPaymentSuccessPayload(string $orderReference, string $accountId, array $overrides = []): array
{
    return array_replace_recursive([
        'event_type' => 'payment_success',
        'requestId' => (string) Str::uuid(),
        'data' => [
            'merchant' => [
                'userId' => $accountId,
            ],
            'transaction' => [
                'transactionId' => 'WEB-test-'.fake()->uuid(),
                'type' => 'online_checkout',
                'time' => now()->toIso8601String(),
            ],
            'order' => [
                'orderReference' => $orderReference,
                'accountId' => $accountId,
                'customerEmail' => 'customer@example.com',
                'amount' => 4000.00,
                'currency' => 'NGN',
            ],
            'tokenizedCardData' => [
                'tokenKey' => 'tok_webhook_test',
                'cardType' => 'Visa',
                'tokenExpiryMonth' => 12,
                'tokenExpiryYear' => 2030,
            ],
        ],
    ], $overrides);
}

/**
 * POST a signed Nomba webhook to the team's inbound endpoint.
 *
 * @param  array<string, mixed>  $payload
 */
function postSignedNombaWebhook(
    TeamProcessorConnection $connection,
    array $payload,
    string $secret = 'whsec_test_default',
): TestResponse {
    return postSignedNombaWebhookAt(
        "/webhooks/nomba/{$connection->inbound_webhook_token}",
        $payload,
        $secret,
    );
}

/**
 * POST a signed Nomba webhook payload to an arbitrary inbound path.
 *
 * @param  array<string, mixed>  $payload
 */
function postSignedNombaWebhookAt(
    string $path,
    array $payload,
    string $secret = 'whsec_test_default',
): TestResponse {
    $timestamp = now()->toIso8601String();
    $signature = nombaWebhookSignature($payload, $secret, $timestamp);

    return test()->postJson($path, $payload, [
        'nomba-signature' => $signature,
        'nomba-timestamp' => $timestamp,
    ]);
}

function fakeNombaCheckout(string $checkoutLink = 'https://checkout.nomba.com/pay/test-invoice'): void
{
    Http::fake([
        '*/v1/auth/token/issue' => Http::response(['code' => '00', 'data' => ['access_token' => 'fake-token']]),
        '*/v1/checkout/order' => Http::response([
            'code' => '00',
            'data' => [
                'checkoutLink' => $checkoutLink,
                'orderReference' => 'nomba-order-ref',
            ],
        ]),
        '*/v1/transactions/accounts/single*' => Http::response([
            'code' => '00',
            'data' => ['status' => 'SUCCESS'],
        ]),
        '*/v1/checkout/tokenized-card-data*' => Http::response([
            'code' => '00',
            'data' => [
                'tokenizedCardDataList' => [[
                    'tokenKey' => 'tok_test',
                    'cardType' => 'Visa',
                    'cardPan' => '************4242',
                    'tokenExpirationDate' => '12/30',
                ]],
            ],
        ]),
    ]);
}

/**
 * @return array{token: string, team: Team, apiKey: ApiKey}
 */
function apiAuthFixture(?ApiKeyMode $mode = ApiKeyMode::Test): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $user);

    $generated = ApiKey::generate($mode, ApiKeyKind::Secret);

    $apiKey = ApiKey::factory()->create([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'mode' => $mode,
        'kind' => ApiKeyKind::Secret,
        'hashed_secret' => $generated['hashedSecret'],
        'last_four' => $generated['lastFour'],
    ]);

    return [
        'token' => $generated['key'],
        'team' => $team,
        'apiKey' => $apiKey,
    ];
}

/**
 * @return array<string, string>
 */
function apiHeaders(string $token, ?string $idempotencyKey = null): array
{
    $headers = [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ];

    if ($idempotencyKey !== null) {
        $headers['Idempotency-Key'] = $idempotencyKey;
    }

    return $headers;
}
