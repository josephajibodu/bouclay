<?php

namespace Database\Factories;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentProcessor;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
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
            'processor' => PaymentProcessor::Nomba,
            'processor_token' => 'tok_'.fake()->numerify('##########'),
            'type' => PaymentMethodType::Card,
            'brand' => fake()->randomElement(['Visa', 'Mastercard', 'Verve']),
            'last4' => fake()->numerify('####'),
            'exp_month' => fake()->numberBetween(1, 12),
            'exp_year' => (int) fake()->numberBetween(2027, 2032),
            'fingerprint' => null,
            'issuer' => null,
            'billing_address_id' => null,
            'is_default' => false,
            'status' => PaymentMethodStatus::Active,
            'custom_data' => null,
        ];
    }

    /**
     * Indicate this is the customer's default payment method.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
