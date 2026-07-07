<?php

namespace Database\Factories;

use App\Enums\OutboundEventType;
use App\Models\Event;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
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
            'type' => OutboundEventType::InvoicePaid,
            'data' => [
                'object' => [
                    'publicId' => 'inv_test',
                ],
            ],
        ];
    }
}
