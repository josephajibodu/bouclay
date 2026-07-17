<?php

use App\Enums\CollectionMode;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('a customer receives a portal token when created', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    expect($customer->portal_token)->not->toBeEmpty();
    expect($customer->portal_token)->toHaveLength(48);
});

test('the portal entry redirects to the subscriptions list when there are multiple subscriptions', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    Subscription::factory()->for($team)->for($customer)->count(2)->create();

    $this->get(route('portal.show', $customer->portal_token))
        ->assertRedirect(route('portal.subscriptions.index', $customer->portal_token));
});

test('the portal entry redirects to the subscription detail when there is only one', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $subscription = Subscription::factory()->for($team)->for($customer)->create();

    $this->get(route('portal.show', $customer->portal_token))
        ->assertRedirect(route('portal.subscriptions.show', [
            'token' => $customer->portal_token,
            'publicId' => $subscription->public_id,
        ]));
});

test('an invalid portal token returns not found', function () {
    $this->get(route('portal.show', 'invalid-token'))
        ->assertNotFound();
});

test('archived customers cannot access the portal', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $token = $customer->portal_token;

    $customer->delete();

    $this->get(route('portal.subscriptions.index', $token))
        ->assertNotFound();
});

test('the subscription detail page renders with paddle-style data', function () {
    $team = Team::factory()->create(['name' => 'Acme Notes', 'default_currency' => 'NGN']);
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);

    $paymentMethod = PaymentMethod::factory()->for($team)->for($customer)->create([
        'brand' => 'Visa',
        'last4' => '4242',
        'is_default' => true,
    ]);

    $customer->update(['default_payment_method_id' => $paymentMethod->id]);

    $subscription = Subscription::factory()->for($team)->for($customer)->create([
        'status' => SubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
        'payment_method_id' => $paymentMethod->id,
    ]);

    $this->get(route('portal.subscriptions.show', [
        'token' => $customer->portal_token,
        'publicId' => $subscription->public_id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/subscriptions/show')
            ->where('business.name', 'Acme Notes')
            ->where('subscription.publicId', $subscription->public_id)
            ->where('subscription.canCancel', true)
            ->has('subscription.nextPayment')
            ->has('subscription.recentPayments'));
});

test('the payments page lists succeeded payments and open invoices', function () {
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $invoice = Invoice::factory()->for($team)->for($customer)->paid()->create([
        'currency' => 'NGN',
        'total' => 500000,
        'amount_due' => 0,
        'amount_paid' => 500000,
    ]);

    Payment::factory()->for($team)->for($customer)->for($invoice)->create([
        'status' => PaymentStatus::Succeeded,
        'amount' => 500000,
        'currency' => 'NGN',
        'processed_at' => now(),
    ]);

    $openInvoice = Invoice::factory()->for($team)->for($customer)->create([
        'status' => InvoiceStatus::Open,
        'currency' => 'NGN',
        'amount_due' => 100000,
        'total' => 100000,
    ]);

    $this->get(route('portal.payments.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/payments/index')
            ->has('payments', 1)
            ->has('openInvoices', 1)
            ->where('openInvoices.0.publicId', $openInvoice->public_id));
});

test('the payment methods page renders saved cards', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    PaymentMethod::factory()->for($team)->for($customer)->create([
        'brand' => 'Visa',
        'last4' => '4242',
        'is_default' => true,
    ]);

    $this->get(route('portal.payment-methods.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/payment-methods/index')
            ->has('paymentMethods', 1)
            ->where('paymentMethods.0.last4', '4242'));
});

test('the portal names the gateway the customer will actually meet', function () {
    $team = Team::factory()->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create([
        'processor' => 'paystack',
    ]);
    $customer = Customer::factory()->for($team)->create();

    // The portal used to hardcode "Secure Nomba checkout" for every merchant.
    $this->get(route('portal.payment-methods.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/payment-methods/index')
            ->where('paymentGateway', 'Paystack'));
});

test('the portal names no gateway when the business has not connected one', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    $this->get(route('portal.payment-methods.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('paymentGateway', null)
            ->where('canUpdatePaymentMethod', false));
});

test('the account page renders customer details', function () {
    $team = Team::factory()->create(['name' => 'Acme Notes']);
    $customer = Customer::factory()->for($team)->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
    ]);

    $this->get(route('portal.account.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/account/index')
            ->where('customer.name', 'Ada Lovelace')
            ->where('customer.email', 'ada@example.com'));
});

test('the portal shows a return link to the subscribed product\'s website', function () {
    $team = Team::factory()->create(['website' => 'https://team-site.example.com']);
    $customer = Customer::factory()->for($team)->create();
    $product = Product::factory()->for($team)->create(['website_url' => 'https://acme.example.com/app']);
    $subscription = Subscription::factory()->for($team)->for($customer)->create();
    SubscriptionItem::factory()->for($subscription)->for($product)->create();

    $this->get(route('portal.account.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('returnUrl', 'https://acme.example.com/app'));
});

test('the portal falls back to the team website when the product has no return link', function () {
    $team = Team::factory()->create(['website' => 'https://team-site.example.com']);
    $customer = Customer::factory()->for($team)->create();
    $product = Product::factory()->for($team)->create(['website_url' => null]);
    $subscription = Subscription::factory()->for($team)->for($customer)->create();
    SubscriptionItem::factory()->for($subscription)->for($product)->create();

    $this->get(route('portal.account.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('returnUrl', 'https://team-site.example.com'));
});

test('the portal has no return link when neither the product nor the team has a website', function () {
    $team = Team::factory()->create(['website' => null]);
    $customer = Customer::factory()->for($team)->create();

    $this->get(route('portal.account.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('returnUrl', null));
});

test('the customer hub exposes the portal url', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();

    attachTeamOwner($team, $owner);
    $owner->switchTeam($team);

    $this->actingAs($owner)
        ->get(route('customers.show', $customer))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('portalUrl', $customer->portalUrl()));
});

test('updating a payment method from the portal redirects to nomba checkout', function () {
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);

    fakeNombaCheckout('https://checkout.nomba.com/pay/portal-card');

    $this->post(route('portal.payment-method.store', $customer->portal_token))
        ->assertRedirect('https://checkout.nomba.com/pay/portal-card');
});

test('the portal payment method callback stores the card and attaches it to subscriptions', function () {
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $subscription = Subscription::factory()->for($team)->for($customer)->create([
        'status' => SubscriptionStatus::Active,
        'collection_mode' => CollectionMode::Automatic,
        'payment_method_id' => null,
    ]);

    $ref = null;
    fakePortalNomba($ref);

    $this->post(route('portal.payment-method.store', $customer->portal_token));

    $this->get(route('portal.payment-method.callback', $customer->portal_token).'?orderReference='.$ref)
        ->assertRedirect(route('portal.payment-methods.index', $customer->portal_token));

    $card = $customer->paymentMethods()->firstOrFail();

    expect($card->processor_token)->toBe('tok_portal_test')
        ->and($subscription->fresh()->payment_method_id)->toBe($card->id);
});

test('a customer can cancel their subscription at period end from the portal', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $subscription = Subscription::factory()->for($team)->for($customer)->create([
        'status' => SubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);

    $this->post(route('portal.subscriptions.cancel', [
        'token' => $customer->portal_token,
        'publicId' => $subscription->public_id,
    ]))->assertRedirect(route('portal.subscriptions.show', [
        'token' => $customer->portal_token,
        'publicId' => $subscription->public_id,
    ]));

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->canceled_at)->not->toBeNull()
        ->and($subscription->scheduledChanges()->count())->toBe(1)
        ->and($subscription->scheduledChanges()->first()->action)->toBe(ScheduledChangeAction::Cancel);
});

test('a customer cannot cancel another customers subscription via the portal', function () {
    $team = Team::factory()->create();
    $customer = Customer::factory()->for($team)->create();
    $otherCustomer = Customer::factory()->for($team)->create();
    $foreign = Subscription::factory()->for($team)->for($otherCustomer)->create();

    $this->post(route('portal.subscriptions.cancel', [
        'token' => $customer->portal_token,
        'publicId' => $foreign->public_id,
    ]))->assertNotFound();
});

/**
 * Fake Nomba checkout + token resolution for portal card update tests.
 */
function fakePortalNomba(?string &$capturedOrderReference): void
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
                        'checkoutLink' => 'https://checkout.nomba.com/pay/portal-card',
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
                        'tokenKey' => 'tok_portal_test',
                        'cardType' => 'Visa',
                        'cardPan' => '418745 **** **** 4242',
                        'tokenExpirationDate' => '12/30',
                    ]],
                ],
            ]),

            default => Http::response(['code' => '99', 'description' => 'unexpected'], 500),
        };
    });
}

test('the portal payments page shows how much of a charge was refunded', function () {
    $team = Team::factory()->create(['default_currency' => 'NGN']);
    $customer = Customer::factory()->for($team)->create(['currency' => 'NGN']);
    $invoice = Invoice::factory()->for($team)->for($customer)->paid()->create([
        'currency' => 'NGN',
        'total' => 500000,
        'amount_due' => 0,
        'amount_paid' => 500000,
    ]);

    $payment = Payment::factory()->for($team)->for($customer)->for($invoice)->create([
        'status' => PaymentStatus::Succeeded,
        'amount' => 500000,
        'currency' => 'NGN',
        'processed_at' => now(),
    ]);

    Refund::factory()->for($payment)->create([
        'team_id' => $team->id,
        'invoice_id' => $invoice->id,
        'amount' => 150000,
        'currency' => 'NGN',
        'status' => RefundStatus::Succeeded,
    ]);

    // A failed refund moved no money, so it must not show as returned.
    Refund::factory()->for($payment)->create([
        'team_id' => $team->id,
        'invoice_id' => $invoice->id,
        'amount' => 90000,
        'currency' => 'NGN',
        'status' => RefundStatus::Failed,
    ]);

    $this->get(route('portal.payments.index', $customer->portal_token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/payments/index')
            ->where('payments.0.refundedAmount', 150000));
});
