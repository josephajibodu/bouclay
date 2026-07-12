<?php

namespace Database\Factories;

use App\Models\Entitlement;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'team_id' => Team::factory(),
            'code' => Str::snake($name),
            'name' => Str::title($name),
            'description' => null,
        ];
    }
}
