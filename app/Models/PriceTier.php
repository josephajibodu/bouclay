<?php

namespace App\Models;

use App\Exceptions\ImmutablePriceViolation;
use Database\Factories\PriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $price_id
 * @property int $tier_index
 * @property int|null $up_to
 * @property int $unit_amount
 * @property int|null $flat_amount
 * @property-read Price $price
 */
#[Fillable(['price_id', 'tier_index', 'up_to', 'unit_amount', 'flat_amount'])]
class PriceTier extends Model
{
    /** @use HasFactory<PriceTierFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Tiers are part of a price's frozen financial shape (schema.md §3) —
     * rewriting or deleting them on a referenced price is the same
     * violation as editing `unit_amount` in place, so the guard covers
     * this backdoor too. ReplacePrice writes fresh tiers on the successor.
     */
    protected static function booted(): void
    {
        $guard = function (PriceTier $tier): void {
            $price = $tier->price;

            if ($price !== null && $price->hasBeenUsed()) {
                throw ImmutablePriceViolation::forColumns($price, ['tiers']);
            }
        };

        static::saving(function (PriceTier $tier) use ($guard): void {
            if ($tier->exists) {
                $guard($tier);
            }
        });

        static::deleting($guard);
    }

    /**
     * Get the price this tier belongs to.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }
}
