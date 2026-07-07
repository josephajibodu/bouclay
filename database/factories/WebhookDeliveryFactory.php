<?php

namespace Database\Factories;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Event;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'event_id' => Event::factory(),
            'status' => WebhookDeliveryStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ];
    }

    /**
     * A successfully delivered webhook.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebhookDeliveryStatus::Succeeded,
            'attempts' => 1,
            'next_attempt_at' => null,
        ]);
    }
}
