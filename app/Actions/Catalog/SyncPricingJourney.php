<?php

namespace App\Actions\Catalog;

use App\Enums\BillingInterval;
use App\Enums\PriceType;
use App\Models\Price;
use App\Models\PricingJourney;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create or update a Pricing Journey — a reusable, Product-scoped
 * commercial offer template (schema.md §3). Unlike the old per-price
 * `price_phases`, a journey step charges an existing, defined `prices` row
 * only — no inline/ad hoc amount — and every step's price must belong to
 * the journey's own product (Plan-agnostic, Product-scoped).
 *
 * A journey stays **freely editable for life**, even after one or more
 * `subscription_schedules` have been copied from it — editing it is defined
 * to never touch a schedule already forked off (that's the entire point of
 * the copy-on-create model), so there's no `hasBeenUsed()`-style guard here
 * the way there is for a price's frozen financial shape.
 */
class SyncPricingJourney
{
    /**
     * @param  array{name: string, description?: string|null, steps: array<int, array{
     *     price_id: int,
     *     quantity?: int,
     *     duration_interval?: string|null,
     *     duration_count?: int|null,
     * }>}  $data  `steps` ordered; index becomes the sequence. The last step
     *             must be terminal (`duration_interval`/`duration_count`
     *             both null); every other step must have both set.
     */
    public function handle(Team $team, Product $product, array $data, ?PricingJourney $journey = null): PricingJourney
    {
        $steps = array_values($data['steps']);

        if ($steps === []) {
            throw new InvalidArgumentException('A pricing journey needs at least one step.');
        }

        $this->assertTerminalShape($steps);

        return DB::transaction(function () use ($team, $product, $data, $steps, $journey): PricingJourney {
            $journey = $journey !== null
                ? tap($journey)->update([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                ])
                : PricingJourney::query()->create([
                    'team_id' => $team->id,
                    'product_id' => $product->id,
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                ]);

            $journey->steps()->delete();

            $firstCurrency = null;
            $firstInterval = null;
            $firstFrequency = null;

            foreach ($steps as $sequence => $step) {
                $price = $this->resolveStepPrice($product, (int) $step['price_id'], $sequence);

                if ($sequence === 0) {
                    $firstCurrency = $price->currency;
                    $firstInterval = $price->billing_interval;
                    $firstFrequency = $price->billing_frequency;
                } else {
                    $this->assertConsistentCadence($price, $firstCurrency, $firstInterval, $firstFrequency, $sequence);
                }

                $journey->steps()->create([
                    'sequence' => $sequence,
                    'price_id' => $price->id,
                    'quantity' => $step['quantity'] ?? 1,
                    'duration_interval' => $step['duration_interval'] ?? null,
                    'duration_count' => $step['duration_count'] ?? null,
                ]);
            }

            return $journey->load('steps.price');
        });
    }

    /**
     * Every step but the last needs a duration; the last must be terminal
     * ("forever") — this is what the advance-schedule worker relies on to
     * find the steady state without special-casing the final index.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function assertTerminalShape(array $steps): void
    {
        $lastIndex = array_key_last($steps);

        foreach ($steps as $sequence => $step) {
            $hasDuration = ($step['duration_interval'] ?? null) !== null && ($step['duration_count'] ?? null) !== null;

            if ($sequence === $lastIndex && $hasDuration) {
                throw new InvalidArgumentException('The last step must be the terminal step — leave its duration empty.');
            }

            if ($sequence !== $lastIndex && ! $hasDuration) {
                throw new InvalidArgumentException("Step {$sequence} needs a duration — only the last step can run forever.");
            }
        }
    }

    private function resolveStepPrice(Product $product, int $priceId, int $sequence): Price
    {
        $price = Price::query()
            ->whereKey($priceId)
            ->where('product_id', $product->id)
            ->first();

        if ($price === null) {
            throw new InvalidArgumentException(
                "Step {$sequence} references a price that doesn't belong to this journey's product — a journey is Plan-agnostic but Product-scoped."
            );
        }

        if ($price->type !== PriceType::Recurring) {
            throw new InvalidArgumentException(
                "Step {$sequence} must charge a recurring price — one-time prices have no billing cadence to step through.",
            );
        }

        return $price;
    }

    private function assertConsistentCadence(
        Price $price,
        ?string $firstCurrency,
        ?BillingInterval $firstInterval,
        ?int $firstFrequency,
        int $sequence,
    ): void {
        if ($price->currency !== $firstCurrency) {
            throw new InvalidArgumentException(
                "Step {$sequence} charges a {$price->currency} price; step 0 is {$firstCurrency} — one currency per journey.",
            );
        }

        if ($price->billing_interval !== $firstInterval || $price->billing_frequency !== $firstFrequency) {
            throw new InvalidArgumentException(
                "Step {$sequence} has a different billing cadence than step 0 — a subscription has one renewal clock, so every step must share one cadence.",
            );
        }
    }
}
