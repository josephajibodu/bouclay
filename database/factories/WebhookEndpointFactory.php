<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generated = WebhookEndpoint::generateSigningSecret();

        return [
            'team_id' => Team::factory(),
            'url' => fake()->url(),
            'signing_secret' => $generated['secret'],
            'active' => true,
        ];
    }

    /**
     * An inactive endpoint that should not receive deliveries.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
