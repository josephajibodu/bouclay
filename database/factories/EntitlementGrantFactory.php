<?php

namespace Database\Factories;

use App\Models\Entitlement;
use App\Models\EntitlementGrant;
use App\Models\Plan;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntitlementGrant>
 */
class EntitlementGrantFactory extends Factory
{
    /**
     * Define the model's default state — a plan grant by default; use
     * `forGrantor()` (or set grantor_type/grantor_id) for product grants.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'entitlement_id' => Entitlement::factory(),
            'grantor_type' => 'plan',
            'grantor_id' => Plan::factory(),
        ];
    }

    /**
     * Grant via a specific plan or product.
     */
    public function forGrantor(Plan|\App\Models\Product $grantor): static
    {
        return $this->state(fn (array $attributes) => [
            'grantor_type' => $grantor->getMorphClass(),
            'grantor_id' => $grantor->id,
        ]);
    }
}
