<?php

namespace Database\Factories;

use App\Enums\PaymentFailureCode;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'invoice_id' => Invoice::factory(),
            'customer_id' => Customer::factory(),
            'processor' => PaymentProcessor::Nomba,
            'processor_reference' => (string) Str::uuid(),
            'amount' => $this->faker->numberBetween(1_000_00, 50_000_00),
            'currency' => 'NGN',
            'status' => PaymentStatus::Succeeded,
            'attempt_number' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'processed_at' => Carbon::now(),
        ];
    }

    /**
     * A failed charge attempt.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed,
            // Code and reason must agree — a fixture that says one thing in
            // the code and another in the reason teaches dunning tests
            // nothing.
            'failure_code' => PaymentFailureCode::InsufficientFunds,
            'failure_reason' => 'Insufficient funds.',
        ]);
    }
}
