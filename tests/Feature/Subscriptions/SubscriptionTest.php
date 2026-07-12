<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\InvoiceIssued;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
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

test('the subscription hub exposes catalog products for item changes', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'product' => $product, 'price' => $price] = subscriptionFixture();

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create(['status' => SubscriptionStatus::Active]);

    $subscription->items()->create([
        'price_id' => $price->id,
        'plan_id' => $price->plan_id,
        'product_id' => $product->id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    $this->actingAs($owner)
        ->get(route('subscriptions.show', $subscription))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('subscriptions/show')
            ->has('products', 1)
            ->where('products.0.id', $product->id)
            ->where('products.0.prices.0.id', $price->id));
});

test('an incomplete manual subscription exposes a payment link on the hub', function () {
    Mail::fake();

    ['owner' => $owner, 'customer' => $customer, 'price' => $price] = subscriptionFixture();

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['kind' => 'price', 'price_id' => $price->id, 'quantity' => 1]],
        ])
        ->assertRedirect();

    $subscription = Subscription::query()->firstOrFail();
    $invoice = $subscription->invoices()->firstOrFail();

    $this->actingAs($owner)
        ->get(route('subscriptions.show', $subscription))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('subscriptions/show')
            ->where('paymentLink', route('hosted.invoices.show', $invoice->public_id)));
});

test('an incomplete automatic subscription without a card exposes the checkout link on the hub', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCheckout('https://checkout.nomba.com/pay/sub-hub');

    $this->actingAs($owner)
        ->post(route('subscriptions.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'automatic',
            'items' => [['kind' => 'price', 'price_id' => $price->id]],
        ])
        ->assertRedirect();

    $subscription = Subscription::query()->firstOrFail();

    $this->actingAs($owner)
        ->get(route('subscriptions.show', $subscription))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('subscriptions/show')
            ->where('paymentLink', 'https://checkout.nomba.com/pay/sub-hub'));
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
