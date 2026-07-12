<?php

use App\Enums\CollectionMode;
use App\Enums\DunningTerminalAction;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\InvoiceIssued;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Models\TeamSettings;
use App\Services\Invoicing\ClassifyPaymentFailure;
use App\Support\DunningConfig;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('dunning config defaults are applied when team settings are created', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();

    $this->actingAs($owner)
        ->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'collection_mode' => 'manual',
            'items' => [['price_id' => $price->id]],
        ]);

    $settings = TeamSettings::query()->where('team_id', $team->id)->firstOrFail();

    expect($settings->dunning_config)->toMatchArray(DunningConfig::defaults()->toArray());
});

test('a declined renewal charge records a classified failure code', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();
    fakeNombaCharge(approved: false);

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->subDay(),
        ]);

    $subscription->items()->create([
        'price_id' => $price->id,
        'plan_id' => $price->plan_id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    $this->artisan('subscriptions:bill-renewals')->assertSuccessful();

    $payment = $subscription->fresh()->invoices()->latest('id')->firstOrFail()->payments()->firstOrFail();

    expect($payment->failure_code)->toBe(ClassifyPaymentFailure::INSUFFICIENT_FUNDS)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

test('the dunning worker retries a past due subscription after the backoff window', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    $subscription = pastDueSubscriptionFixture($team, $customer, $price, $card);

    fakeNombaCharge();

    $this->artisan('subscriptions:process-dunning')->assertSuccessful();

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->invoices()->latest('id')->firstOrFail()->status)->toBe(InvoiceStatus::Paid)
        ->and($subscription->invoices()->latest('id')->firstOrFail()->payments()->count())->toBe(2);
});

test('the dunning worker skips retries until the backoff window elapses', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    $subscription = pastDueSubscriptionFixture($team, $customer, $price, $card, now()->subHours(6));

    $this->artisan('subscriptions:process-dunning')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->invoices()->latest('id')->firstOrFail()->payments()->count())->toBe(1);
});

test('dunning exhaustion cancels the subscription after max attempts', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    $subscription = pastDueSubscriptionFixture($team, $customer, $price, $card);
    $invoice = $subscription->invoices()->latest('id')->firstOrFail();

    Payment::factory()->for($team)->for($invoice)->for($customer)->for($card, 'paymentMethod')->create([
        'status' => PaymentStatus::Failed,
        'failure_code' => ClassifyPaymentFailure::INSUFFICIENT_FUNDS,
        'attempt_number' => 2,
        'created_at' => now()->subDays(3),
    ]);

    Payment::factory()->for($team)->for($invoice)->for($customer)->for($card, 'paymentMethod')->create([
        'status' => PaymentStatus::Failed,
        'failure_code' => ClassifyPaymentFailure::INSUFFICIENT_FUNDS,
        'attempt_number' => 3,
        'created_at' => now()->subDays(2),
    ]);

    Payment::factory()->for($team)->for($invoice)->for($customer)->for($card, 'paymentMethod')->create([
        'status' => PaymentStatus::Failed,
        'failure_code' => ClassifyPaymentFailure::INSUFFICIENT_FUNDS,
        'attempt_number' => 4,
        'created_at' => now()->subDay(),
    ]);

    $this->artisan('subscriptions:process-dunning')->assertSuccessful();

    $subscription->refresh();
    $invoice->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($invoice->status)->toBe(InvoiceStatus::Uncollectible);
});

test('a hard decline skips further retries and applies the terminal action immediately', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    $subscription = pastDueSubscriptionFixture($team, $customer, $price, $card);
    $invoice = $subscription->invoices()->latest('id')->firstOrFail();

    $invoice->payments()->firstOrFail()->forceFill([
        'failure_code' => ClassifyPaymentFailure::CARD_EXPIRED,
        'failure_reason' => 'Card expired',
    ])->save();

    $this->artisan('subscriptions:process-dunning')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Canceled);
});

test('merchants can force a manual retry from the subscription hub', function () {
    Mail::fake();

    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();
    TeamProcessorConnection::factory()->for($team)->testConnected()->create();

    $subscription = pastDueSubscriptionFixture($team, $customer, $price, $card);

    fakeNombaCharge();

    $this->actingAs($owner)
        ->post(route('subscriptions.retry-payment', $subscription))
        ->assertRedirect();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('the subscription hub exposes dunning attempt metadata for past due subscriptions', function () {
    ['owner' => $owner, 'team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();

    $subscription = pastDueSubscriptionFixture($team, $customer, $price, $card);

    $this->actingAs($owner)
        ->get(route('subscriptions.show', $subscription))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dunning.attempt', 1)
            ->where('dunning.maxAttempts', 4)
            ->where('dunning.canRetryNow', true));
});

test('incomplete subscriptions expire after the configured grace window', function () {
    ['team' => $team, 'customer' => $customer] = subscriptionFixture();

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'status' => SubscriptionStatus::Incomplete,
            'created_at' => now()->subDays(8),
        ]);

    $this->artisan('subscriptions:expire-incomplete')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::IncompleteExpired);
});

test('dunning exhaustion with leave open keeps the subscription past due and the invoice open', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();

    configureTeamDunning($team, ['terminal_action' => DunningTerminalAction::LeaveOpen->value]);

    $subscription = exhaustedPastDueSubscriptionFixture($team, $customer, $price, $card);

    $this->artisan('subscriptions:process-dunning')->assertSuccessful();

    $invoice = $subscription->invoices()->latest('id')->firstOrFail();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Open);
});

test('dunning exhaustion with pause moves the subscription to paused and marks the invoice uncollectible', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();

    configureTeamDunning($team, ['terminal_action' => DunningTerminalAction::Pause->value]);

    $subscription = exhaustedPastDueSubscriptionFixture($team, $customer, $price, $card);

    $this->artisan('subscriptions:process-dunning')->assertSuccessful();

    $invoice = $subscription->invoices()->latest('id')->firstOrFail();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Paused)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Uncollectible);
});

test('an overdue manual renewal invoice ages the subscription to past due', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();

    $subscription = manualPastDueCandidateFixture($team, $customer, $price, dueAt: now()->subDay());

    $this->artisan('subscriptions:process-manual-dunning')->assertSuccessful();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue);
});

test('manual invoice dunning sends reminders and cancels after the configured maximum', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();

    configureTeamDunning($team, [
        'max_attempts' => 2,
        'retry_intervals_days' => [0, 0],
    ]);

    $subscription = manualPastDueCandidateFixture($team, $customer, $price, dueAt: now()->subDays(3));
    $subscription->apply('markPastDue');

    $this->artisan('subscriptions:process-manual-dunning')->assertSuccessful();
    $this->artisan('subscriptions:process-manual-dunning')->assertSuccessful();

    $invoice = $subscription->invoices()->latest('id')->firstOrFail();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Canceled)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Uncollectible)
        ->and((int) ($invoice->custom_data['dunning_reminder_count'] ?? 0))->toBe(2);

    Mail::assertQueued(InvoiceIssued::class, 2);
});

test('bill renewals does not advance past due subscriptions', function () {
    Mail::fake();

    ['team' => $team, 'customer' => $customer, 'price' => $price] = subscriptionFixture();
    $card = PaymentMethod::factory()->for($team)->for($customer)->create();

    $subscription = pastDueSubscriptionFixture($team, $customer, $price, $card);
    $periodEnd = $subscription->current_period_end;

    $this->artisan('subscriptions:bill-renewals')->assertSuccessful();

    expect($subscription->fresh()->current_period_end?->eq($periodEnd))->toBeTrue()
        ->and($subscription->invoices()->count())->toBe(1);
});

function pastDueSubscriptionFixture(
    Team $team,
    Customer $customer,
    Price $price,
    PaymentMethod $card,
    ?CarbonInterface $failedAt = null,
): Subscription {
    $failedAt ??= now()->subDays(2);
    $failedAt = Carbon::instance($failedAt);
    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'collection_mode' => 'automatic',
            'payment_method_id' => $card->id,
            'status' => SubscriptionStatus::PastDue,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addWeek(),
        ]);

    $subscription->items()->create([
        'price_id' => $price->id,
        'plan_id' => $price->plan_id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    $invoice = Invoice::factory()
        ->for($team)
        ->for($customer)
        ->for($subscription)
        ->create([
            'status' => InvoiceStatus::Open,
            'billing_reason' => InvoiceBillingReason::SubscriptionCycle,
            'collection_mode' => 'automatic',
        ]);

    Payment::factory()->for($team)->for($invoice)->for($customer)->for($card, 'paymentMethod')->create([
        'status' => PaymentStatus::Failed,
        'failure_code' => ClassifyPaymentFailure::INSUFFICIENT_FUNDS,
        'attempt_number' => 1,
        'created_at' => $failedAt,
    ]);

    return $subscription->fresh(['invoices.payments']);
}

function configureTeamDunning(Team $team, array $overrides): void
{
    $config = array_merge(DunningConfig::defaults()->toArray(), $overrides);

    if ($team->settings) {
        $team->settings->update(['dunning_config' => $config]);

        return;
    }

    $team->settings()->create([
        'invoice_prefix' => 'INV',
        'next_invoice_number' => 1,
        'billing_timezone' => 'UTC',
        'tax_behavior' => 'exclusive',
        'dunning_config' => $config,
    ]);
}

function exhaustedPastDueSubscriptionFixture(
    Team $team,
    Customer $customer,
    Price $price,
    PaymentMethod $card,
): Subscription {
    $subscription = pastDueSubscriptionFixture($team, $customer, $price, $card);
    $invoice = $subscription->invoices()->latest('id')->firstOrFail();

    foreach ([2, 3, 4] as $attempt) {
        Payment::factory()->for($team)->for($invoice)->for($customer)->for($card, 'paymentMethod')->create([
            'status' => PaymentStatus::Failed,
            'failure_code' => ClassifyPaymentFailure::INSUFFICIENT_FUNDS,
            'attempt_number' => $attempt,
            'created_at' => now()->subDays(5 - $attempt),
        ]);
    }

    return $subscription->fresh(['invoices.payments']);
}

function manualPastDueCandidateFixture(
    Team $team,
    Customer $customer,
    Price $price,
    CarbonInterface $dueAt,
): Subscription {
    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'collection_mode' => CollectionMode::Manual,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addWeek(),
        ]);

    $subscription->items()->create([
        'price_id' => $price->id,
        'plan_id' => $price->plan_id,
        'product_id' => $price->product_id,
        'quantity' => 1,
        'status' => SubscriptionItemStatus::Active,
    ]);

    Invoice::factory()
        ->for($team)
        ->for($customer)
        ->for($subscription)
        ->create([
            'status' => InvoiceStatus::Open,
            'billing_reason' => InvoiceBillingReason::SubscriptionCycle,
            'collection_mode' => CollectionMode::Manual,
            'due_at' => Carbon::instance($dueAt),
        ]);

    return $subscription->fresh(['invoices']);
}
