<?php

use App\Models\Customer;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Drives the Nomba hosted-checkout charge flow with faked HTTP responses, so
 * the controller logic (order → callback → token capture → payment method) is
 * proven without a live Nomba account.
 */
function fakeNomba(?string &$capturedOrderReference): void
{
    Http::fake(function ($request) use (&$capturedOrderReference) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/v1/auth/token/issue') => Http::response([
                'code' => '00',
                'data' => ['access_token' => 'fake-access-token'],
            ]),

            str_contains($url, '/v1/checkout/order') => (function () use ($request, &$capturedOrderReference) {
                $capturedOrderReference = $request->data()['order']['orderReference'];

                return Http::response([
                    'code' => '00',
                    'data' => [
                        'checkoutLink' => 'https://checkout.nomba.com/pay/abc123',
                        'orderReference' => $capturedOrderReference,
                    ],
                ]);
            })(),

            str_contains($url, '/v1/transactions/accounts/single') => Http::response([
                'code' => '00',
                'data' => ['status' => 'SUCCESS'],
            ]),

            str_contains($url, '/v1/checkout/tokenized-card-data') => Http::response([
                'code' => '00',
                'data' => [
                    'tokenizedCardDataList' => [[
                        'tokenKey' => 'tok_987654',
                        'cardType' => 'Visa',
                        'cardPan' => '418745 **** **** 1119',
                        'tokenExpirationDate' => '08/28',
                    ]],
                ],
            ]),

            default => Http::response(['code' => '99', 'description' => 'unexpected'], 500),
        };
    });
}

function connectedTeamAndOwner(): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    return [$owner, $team];
}

test('charging a customer creates a Nomba checkout and returns to the customer', function () {
    [$owner, $team] = connectedTeamAndOwner();
    $customer = Customer::factory()->for($team)->create();
    $ref = null;
    fakeNomba($ref);

    $this->actingAs($owner)
        ->post(route('customers.charge.store', $customer), [
            'amount' => 5000,
            'set_default' => true,
        ])
        ->assertRedirect(route('customers.show', $customer));

    // The hosted-checkout order was created (its link is flashed to the UI).
    expect($ref)->not->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/checkout/order'));
});

test('the checkout is scoped to the subaccount, card-only, with the parent in the header', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    $connection = TeamProcessorConnection::factory()->for($team)
        ->testConnected()->withTestSubaccount()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);
    $customer = Customer::factory()->for($team)->create();
    $ref = null;
    fakeNomba($ref);

    $this->actingAs($owner)
        ->post(route('customers.charge.store', $customer), ['amount' => 5000]);

    Http::assertSent(function ($request) use ($connection) {
        if (! str_contains($request->url(), '/v1/checkout/order')) {
            return false;
        }

        $order = $request->data()['order'];

        // Funds deposit into the subaccount; only cards are offered.
        expect($order['accountId'])->toBe($connection->nomba_test_subaccount_id)
            ->and($order['allowedPaymentMethods'])->toBe(['Card']);

        // The account header is always the parent, never the subaccount.
        return $request->hasHeader('accountId', $connection->nomba_test_account_id);
    });
});

test('charging without a connected Nomba account is blocked', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);
    $customer = Customer::factory()->for($team)->create();

    $this->actingAs($owner)
        ->post(route('customers.charge.store', $customer), ['amount' => 5000])
        ->assertRedirect();

    expect($customer->paymentMethods()->count())->toBe(0);
});

test('the callback stores the tokenised card and sets it as default', function () {
    [$owner, $team] = connectedTeamAndOwner();
    $customer = Customer::factory()->for($team)->create();
    $ref = null;
    fakeNomba($ref);

    // Kick off the checkout so the intent is cached under $ref.
    $this->actingAs($owner)
        ->post(route('customers.charge.store', $customer), [
            'amount' => 5000,
            'set_default' => true,
        ]);

    // Nomba redirects the customer back to the callback.
    $this->actingAs($owner)
        ->get(route('customers.charge.callback', $customer).'?orderReference='.$ref)
        ->assertRedirect(route('customers.show', $customer));

    $card = $customer->paymentMethods()->firstOrFail();

    expect($card->processor_token)->toBe('tok_987654')
        ->and($card->brand)->toBe('Visa')
        ->and($card->last4)->toBe('1119')
        ->and($card->exp_month)->toBe(8)
        ->and($card->exp_year)->toBe(2028)
        ->and($card->is_default)->toBeTrue()
        ->and($customer->fresh()->default_payment_method_id)->toBe($card->id)
        ->and($card->custom_data['mode'])->toBe('test');
});

test('charging uses live credentials when a live account is connected', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    TeamProcessorConnection::factory()->for($team)->liveConnected()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);
    $customer = Customer::factory()->for($team)->create();
    $ref = null;
    fakeNomba($ref);

    $this->actingAs($owner)
        ->post(route('customers.charge.store', $customer), ['amount' => 5000, 'set_default' => true]);

    $this->actingAs($owner)
        ->get(route('customers.charge.callback', $customer).'?orderReference='.$ref)
        ->assertRedirect(route('customers.show', $customer));

    // The card is minted against the live environment.
    $card = $customer->paymentMethods()->firstOrFail();
    expect($card->custom_data['mode'])->toBe('live');

    // And the calls went to Nomba's production host, not the sandbox.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.nomba.com/v1/checkout/order'));
});

test('live is preferred over test when both are connected', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    TeamProcessorConnection::factory()->for($team)->testConnected()->liveConnected()->create();
    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);
    $customer = Customer::factory()->for($team)->create();
    $ref = null;
    fakeNomba($ref);

    $this->actingAs($owner)
        ->post(route('customers.charge.store', $customer), ['amount' => 5000]);
    $this->actingAs($owner)
        ->get(route('customers.charge.callback', $customer).'?orderReference='.$ref);

    expect($customer->paymentMethods()->firstOrFail()->custom_data['mode'])->toBe('live');
});

test('a failed verification saves no card', function () {
    [$owner, $team] = connectedTeamAndOwner();
    $customer = Customer::factory()->for($team)->create();
    $ref = null;

    Http::fake(function ($request) use (&$ref) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/v1/auth/token/issue') => Http::response(['code' => '00', 'data' => ['access_token' => 'x']]),
            str_contains($url, '/v1/checkout/order') => (function () use ($request, &$ref) {
                $ref = $request->data()['order']['orderReference'];

                return Http::response(['code' => '00', 'data' => ['checkoutLink' => 'https://checkout.nomba.com/pay/x', 'orderReference' => $ref]]);
            })(),
            // Verification reports the payment did not succeed.
            str_contains($url, '/v1/transactions/accounts/single') => Http::response(['code' => '00', 'data' => ['status' => 'FAILED']]),
            default => Http::response(['code' => '99'], 500),
        };
    });

    $this->actingAs($owner)
        ->post(route('customers.charge.store', $customer), ['amount' => 5000]);

    $this->actingAs($owner)
        ->get(route('customers.charge.callback', $customer).'?orderReference='.$ref)
        ->assertRedirect(route('customers.show', $customer));

    expect($customer->paymentMethods()->count())->toBe(0);
});
