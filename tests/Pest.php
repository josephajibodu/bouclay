<?php

use App\Actions\Invoicing\ChargeInvoice;
use App\Models\Customer;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
