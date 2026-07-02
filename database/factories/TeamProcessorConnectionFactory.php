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
        ];
    }

    /**
     * Indicate that test credentials are connected.
     */
    public function testConnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'nomba_test_account_id' => fake()->uuid(),
            'nomba_test_client_id' => fake()->uuid(),
            'nomba_test_client_secret' => fake()->sha256(),
            'test_connected_at' => now(),
        ]);
    }

    /**
     * Indicate that live credentials are connected.
     */
    public function liveConnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'nomba_live_account_id' => fake()->uuid(),
            'nomba_live_client_id' => fake()->uuid(),
            'nomba_live_client_secret' => fake()->sha256(),
            'live_connected_at' => now(),
        ]);
    }

    /**
     * Indicate that a test sub-account is set, in addition to the main account.
     */
    public function withTestSubaccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'nomba_test_subaccount_id' => fake()->uuid(),
        ]);
    }
}
