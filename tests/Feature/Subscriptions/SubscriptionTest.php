<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\InvoiceIssued;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\TrialOffer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A team + owner with a recurring price and a customer, all in NGN.
 */
test('the subscriptions index renders', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    Subscription::factory()->for($team)->for($customer)->create();

    $this->actingAs($owner)
        ->get(route('subscriptions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('subscriptions/index')
            ->has('subscriptions.data', 1)
            ->where('canManage', true));
});

test('a member without the subscriptions permission cannot view the page', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = Team::factory()->create();
    attachTeamOwner($team, $owner);
    attachTeamMember($team, $member, 'Finance');
    $member->switchTeam($team);

    $this->actingAs($member)
        ->get(route('subscriptions.index'))
        ->assertForbidden();
});

test('creating a free-trial subscription lands it in trialing with a computed trial end', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    $offer = TrialOffer::factory()->create(['team_id' => $team->id]);

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['kind' => 'trial', 'trial_offer_id' => $offer->id]],
        ])
        ->assertRedirect();

    $subscription = Subscription::query()->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->items)->toHaveCount(1)
        ->and($subscription->items->first()->currentTrial)->not->toBeNull();
});

test('a paid trial charges the intro price now, bills each cycle, and is active — not trialing', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();

    $product = Product::factory()->for($team)->create();
    // ₦1,000/month intro price for 3 months, then ₦40,000/month.
    $introPrice = Price::factory()->for($team)->for($product)->create([
        'unit_amount' => 100_000, 'currency' => 'NGN', 'billing_interval' => 'month', 'billing_frequency' => 1,
    ]);
    $regularPrice = Price::factory()->for($team)->for($product)->create([
        'unit_amount' => 4_000_000, 'currency' => 'NGN',
    ]);
    $offer = TrialOffer::factory()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'trial_price_id' => $introPrice->id,
        'transition_price_id' => $regularPrice->id,
        'duration_iterations' => 3,
    ]);
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'items' => [['kind' => 'trial', 'trial_offer_id' => $offer->id]],
        ])
        ->assertRedirect();

    $subscription = Subscription::query()->firstOrFail();

    // A paid trial follows incomplete -> active (payment captured at signup),
    // not the free-trial `trialing` path.
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->items->first()->price_id)->toBe($introPrice->id)
        // Next charge is one intro cycle away (a month), well before conversion.
        ->and($subscription->current_period_end->lessThan(now()->addMonths(2)))->toBeTrue()
        // The trial converts after 3 intro cycles (~3 months out).
        ->and($subscription->trial_ends_at->greaterThan(now()->addMonths(2)))->toBeTrue()
        ->and($subscription->current_period_end->lessThan($subscription->trial_ends_at))->toBeTrue();

    // The intro price was actually charged — a real invoice + succeeded
    // transaction, not the old simulated activation.
    $invoice = $subscription->invoices()->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->total)->toBe(100_000)
        ->and($invoice->number)->toMatch('/^[A-Za-z]+-\d+$/')
        ->and($invoice->payments()->firstOrFail()->status)->toBe(PaymentStatus::Succeeded);
});

test('a manual subscription with a regular price stays incomplete until the invoice is paid', function () {
    Mail::fake();

    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = subscriptionFixture();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['kind' => 'price', 'price_id' => $price->id, 'quantity' => 2]],
        ])
        ->assertRedirect();

    $subscription = Subscription::query()->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Incomplete)
        ->and($subscription->current_period_end)->not->toBeNull()
        ->and($subscription->items->first()->quantity)->toBe(2);

    $invoice = $subscription->invoices()->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->due_at)->not->toBeNull()
        ->and($invoice->amount_paid)->toBe(0)
        ->and($invoice->payments()->count())->toBe(0);

    Mail::assertQueued(InvoiceIssued::class, function (InvoiceIssued $mail) use ($invoice): bool {
        return $mail->invoice->is($invoice)
            && str_contains($mail->actionUrl, $invoice->public_id);
    });
});

test('an automatic subscription with no card waits at incomplete, generates checkout, and emails the customer', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'items' => [['kind' => 'price', 'price_id' => $price->id]],
        ])
        ->assertRedirect();

    $subscription = Subscription::query()->firstOrFail();
    expect($subscription->status)->toBe(SubscriptionStatus::Incomplete);

    $invoice = $subscription->invoices()->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($invoice->payments()->count())->toBe(0)
        ->and($invoice->custom_data['checkout_link'] ?? null)->toStartWith('https://');

    Mail::assertQueued(InvoiceIssued::class);
});

test('an automatic subscription with a card on file is really charged and activates', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'items' => [['kind' => 'price', 'price_id' => $price->id]],
        ])
        ->assertRedirect();

    $subscription = Subscription::query()->firstOrFail();
    expect($subscription->status)->toBe(SubscriptionStatus::Active);

    $invoice = $subscription->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->payment_method_id)->toBe($card->id)
        ->and($payment->public_id)->toStartWith('pay_');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/checkout/tokenized-card-payment'));
});

test('a declined charge leaves the subscription incomplete with a failed payment on the open invoice', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge(approved: false);

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'items' => [['kind' => 'price', 'price_id' => $price->id]],
        ])
        ->assertRedirect();

    $subscription = Subscription::query()->firstOrFail();
    $invoice = $subscription->invoices()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();

    // No access granted on a decline — the whole point of `incomplete`.
    expect($subscription->status)->toBe(SubscriptionStatus::Incomplete)
        ->and($invoice->status)->toBe(InvoiceStatus::Open)
        ->and($payment->status)->toBe(PaymentStatus::Failed)
        ->and($payment->failure_reason)->not->toBeNull();
});

test('a subscription cannot be created without any items', function () {
    ['owner' => $owner, 'customer' => $customer] = subscriptionFixture();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [],
        ])
        ->assertSessionHasErrors('items');
});

test('a product cannot appear twice — a plain line plus a trial for the same product is rejected', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'product' => $product, 'price' => $price] = subscriptionFixture();
    // A trial offer on the SAME product as the regular price line.
    $offer = TrialOffer::factory()->create([
        'team_id' => $team->id,
        'product_id' => $product->id,
        'trial_price_id' => Price::factory()->for($team)->for($product)->free()->create(['currency' => 'NGN'])->id,
        'transition_price_id' => $price->id,
    ]);

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [
                ['kind' => 'price', 'price_id' => $price->id],
                ['kind' => 'trial', 'trial_offer_id' => $offer->id],
            ],
        ])
        ->assertSessionHasErrors('items');

    expect(Subscription::query()->count())->toBe(0);
});

test('the same trial offer cannot be used twice by one customer', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    $offer = TrialOffer::factory()->create(['team_id' => $team->id]);

    $payload = [
        'customer_id' => $customer->id,
        'collection_mode' => 'manual',
        'items' => [['kind' => 'trial', 'trial_offer_id' => $offer->id]],
    ];

    $this->actingAs($owner)->post(route('subscriptions.store'), $payload)->assertRedirect();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), $payload)
        ->assertSessionHasErrors('items');

    expect(Subscription::query()->count())->toBe(1);
});

test('pausing then resuming a subscription moves through the states', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    $subscription = Subscription::factory()->for($team)->for($customer)->create();

    $this->actingAs($owner)
        ->post(route('subscriptions.pause', $subscription))
        ->assertRedirect();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Paused);

    $this->actingAs($owner)
        ->post(route('subscriptions.resume', $subscription))
        ->assertRedirect();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

test('cancelling immediately ends the subscription', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    $subscription = Subscription::factory()->for($team)->for($customer)->create();

    $this->actingAs($owner)
        ->post(route('subscriptions.cancel', $subscription), ['mode' => 'immediately'])
        ->assertRedirect();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->canceled_at)->not->toBeNull();
});

test('cancelling at period end keeps it active but schedules the change', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer] = subscriptionFixture();
    $subscription = Subscription::factory()->for($team)->for($customer)->create();

    $this->actingAs($owner)
        ->post(route('subscriptions.cancel', $subscription), ['mode' => 'period_end'])
        ->assertRedirect();

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->canceled_at)->not->toBeNull()
        ->and($subscription->scheduledChanges()->count())->toBe(1);
});

test('another team cannot act on a subscription', function () {
    ['owner' => $owner] = subscriptionFixture();

    $otherTeam = Team::factory()->create();
    $otherCustomer = Customer::factory()->for($otherTeam)->create();
    $foreign = Subscription::factory()->for($otherTeam)->for($otherCustomer)->create();

    $this->actingAs($owner)
        ->post(route('subscriptions.cancel', $foreign), ['mode' => 'immediately'])
        ->assertNotFound();
});
