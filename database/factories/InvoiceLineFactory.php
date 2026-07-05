<?php

namespace Database\Factories;

use App\Enums\InvoiceLineKind;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitAmount = $this->faker->numberBetween(1_000_00, 20_000_00);
        $quantity = 1;

        return [
            'invoice_id' => Invoice::factory(),
            'kind' => InvoiceLineKind::OneTime,
            'description' => $this->faker->words(2, true),
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'subtotal' => $unitAmount * $quantity,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total' => $unitAmount * $quantity,
            'proration' => false,
        ];
    }
}
