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
            'type' => OutboundEventType::InvoiceUpdated,
            'data' => [
                'object' => [
                    'publicId' => 'inv_test',
                    // Consumers read the outcome off the object, never the
                    // event name (schema.md §9).
                    'status' => 'paid',
                ],
            ],
        ];
    }
}
