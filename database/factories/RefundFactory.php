<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `invoice_id` is denormalised from the refunded payment (schema.md §8),
     * so handing the factory a payment yields a consistent row.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'payment_id' => Payment::factory(),
            'invoice_id' => fn (array $attributes) => Payment::query()->find($attributes['payment_id'])?->invoice_id,
            'amount' => 100000,
            'currency' => 'NGN',
            'reason' => null,
            'status' => RefundStatus::Succeeded,
            'processor_reference' => null,
        ];
    }
}
