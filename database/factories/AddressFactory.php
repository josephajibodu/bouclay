<?php

namespace Database\Factories;

use App\Enums\AddressType;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $customer = Customer::factory();

        return [
            'team_id' => Team::factory(),
            'customer_id' => $customer,
            'type' => AddressType::Billing,
            'name' => null,
            'line1' => fake()->streetAddress(),
            'line2' => null,
            'city' => fake()->city(),
            'region' => fake()->randomElement(['Oyo', 'Lagos', 'Abuja', 'Kano', 'Rivers']),
            'postal_code' => fake()->postcode(),
            'country' => fake()->countryCode(),
            'phone' => null,
            'is_default' => false,
        ];
    }

    /**
     * Indicate this is the default address for its type.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
