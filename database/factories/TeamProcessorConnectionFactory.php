<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamProcessorConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamProcessorConnection>
 */
class TeamProcessorConnectionFactory extends Factory
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
            'processor' => 'nomba',
            'is_default' => true,
        ];
    }

    /**
     * Indicate that test credentials are connected.
     */
    public function testConnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'test_credentials' => [
                'account_id' => fake()->uuid(),
                'client_id' => fake()->uuid(),
                'client_secret' => fake()->sha256(),
                'webhook_secret' => 'whsec_test_default',
            ],
            'test_connected_at' => now(),
        ]);
    }

    /**
     * Indicate that live credentials are connected.
     */
    public function liveConnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'live_credentials' => [
                'account_id' => fake()->uuid(),
                'client_id' => fake()->uuid(),
                'client_secret' => fake()->sha256(),
                'webhook_secret' => 'whsec_live_default',
            ],
            'live_connected_at' => now(),
        ]);
    }

    /**
     * Indicate that a test sub-account is set, in addition to the main account.
     */
    public function withTestSubaccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'test_credentials' => [
                ...($attributes['test_credentials'] ?? []),
                'subaccount_id' => fake()->uuid(),
            ],
        ]);
    }
}
