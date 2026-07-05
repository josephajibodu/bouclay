<?php

namespace Database\Factories;

use App\Enums\CollectionMode;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialEndBehavior;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'customer_id' => Customer::factory(),
            'type' => 'default',
            'status' => SubscriptionStatus::Active,
            'currency' => 'NGN',
            'collection_mode' => CollectionMode::Automatic,
            'payment_method_id' => null,
            'trial_end_behavior' => TrialEndBehavior::CreateInvoice,
            'billing_cycle_anchor_on_trial_end' => 'now',
            'current_period_start' => Carbon::now(),
            'current_period_end' => Carbon::now()->addMonth(),
        ];
    }

    /**
     * A subscription on a free trial.
     */
    public function trialing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => Carbon::now()->addDays(14),
            'current_period_end' => Carbon::now()->addDays(14),
        ]);
    }

    /**
     * A subscription awaiting its first payment.
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Incomplete,
            'current_period_end' => null,
        ]);
    }

    /**
     * A canceled subscription.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => Carbon::now(),
            'ends_at' => Carbon::now(),
        ]);
    }

    /**
     * Bill by invoice rather than an automatic card charge.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'collection_mode' => CollectionMode::Manual,
        ]);
    }
}
