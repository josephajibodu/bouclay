<?php

use App\Enums\ScheduledChangeAction;
use App\Enums\SubscriptionStatus;
use App\Models\ScheduledChange;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

test('the scheduled changes command cancels a subscription at period end', function () {
    ['team' => $team, 'customer' => $customer] = subscriptionFixture();

    $periodEnd = Carbon::parse('2026-06-01 12:00:00');

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create([
            'status' => SubscriptionStatus::Active,
            'canceled_at' => Carbon::parse('2026-05-15 12:00:00'),
            'ends_at' => $periodEnd,
            'current_period_end' => $periodEnd,
        ]);

    ScheduledChange::factory()->create([
        'subscription_id' => $subscription->id,
        'action' => ScheduledChangeAction::Cancel,
        'effective_at' => $periodEnd,
    ]);

    $this->travelTo($periodEnd->copy()->addMinute());

    $this->artisan('subscriptions:apply-scheduled-changes')->assertSuccessful();

    $subscription->refresh();
    $change = $subscription->scheduledChanges()->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
        ->and($subscription->ends_at->equalTo($periodEnd))->toBeTrue()
        ->and($change->applied_at)->not->toBeNull();
});

test('the scheduled changes command pauses and resumes a subscription', function () {
    ['team' => $team, 'customer' => $customer] = subscriptionFixture();

    $subscription = Subscription::factory()
        ->for($team)
        ->for($customer)
        ->create(['status' => SubscriptionStatus::Active]);

    $pauseAt = now()->subHour();
    $resumeAt = now()->subMinutes(10);

    ScheduledChange::factory()->create([
        'subscription_id' => $subscription->id,
        'action' => ScheduledChangeAction::Pause,
        'effective_at' => $pauseAt,
        'payload' => ['resumes_at' => now()->addWeek()->toISOString()],
    ]);

    ScheduledChange::factory()->create([
        'subscription_id' => $subscription->id,
        'action' => ScheduledChangeAction::Resume,
        'effective_at' => $resumeAt,
    ]);

    $this->artisan('subscriptions:apply-scheduled-changes')->assertSuccessful();

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->scheduledChanges()->whereNull('applied_at')->count())->toBe(0);
});
