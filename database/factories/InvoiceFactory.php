<?php

namespace Database\Factories;

use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(1_000_00, 50_000_00);

        return [
            'team_id' => Team::factory(),
            'customer_id' => Customer::factory(),
            'subscription_id' => null,
            'number' => 'BCL-'.$this->faker->unique()->numberBetween(1000, 999999),
            'status' => InvoiceStatus::Open,
            'billing_reason' => InvoiceBillingReason::Manual,
            'collection_mode' => CollectionMode::Automatic,
            'currency' => 'NGN',
            'subtotal' => $subtotal,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => $subtotal,
            'amount_paid' => 0,
            'amount_due' => $subtotal,
            'finalized_at' => Carbon::now(),
        ];
    }

    /**
     * A paid invoice.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => InvoiceStatus::Paid,
            'amount_paid' => $attributes['total'],
            'amount_due' => 0,
            'paid_at' => Carbon::now(),
        ]);
    }
}
