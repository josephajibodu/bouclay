<?php

namespace Database\Factories;

use App\Enums\ApiKeyKind;
use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $generated = ApiKey::generate(ApiKeyMode::Test, ApiKeyKind::Secret);

        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(2, true),
            'mode' => ApiKeyMode::Test,
            'kind' => ApiKeyKind::Secret,
            'hashed_secret' => $generated['hashedSecret'],
            'last_four' => $generated['lastFour'],
        ];
    }

    /**
     * Indicate that the key is a live key.
     */
    public function live(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => ApiKeyMode::Live,
        ]);
    }

    /**
     * Indicate that the key is publishable.
     */
    public function publishable(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => ApiKeyKind::Publishable,
        ]);
    }

    /**
     * Indicate that the key has been revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
