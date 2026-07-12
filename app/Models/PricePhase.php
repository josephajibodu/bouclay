<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Database\Factories\PricePhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a price's phase schedule (schema.md §3) — the generalized
 * mechanism for anything beyond a simple trial: a paid multi-iteration
 * trial, a transition to a different plan's price when a trial ends, or a
 * true multi-step ramp. A simple trial (`prices.trial_length`) never
 * touches this table.
 *
 * @property int $id
 * @property int $price_id
 * @property int $sequence
 * @property int $charge_price_id
 * @property BillingInterval $duration_interval
 * @property int $duration_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Price $price
 * @property-read Price $chargePrice
 */
#[Fillable(['price_id', 'sequence', 'charge_price_id', 'duration_interval', 'duration_count'])]
class PricePhase extends Model
{
    /** @use HasFactory<PricePhaseFactory> */
    use HasFactory;

    /**
     * Get the "home" price this phase schedule is attached to — what a
     * subscription_item nominally references.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /**
     * Get the price actually charged during this phase — possibly a
     * trial-priced row, or a price under a different plan entirely.
     *
     * @return BelongsTo<Price, $this>
     */
    public function chargePrice(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'charge_price_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'duration_interval' => BillingInterval::class,
            'duration_count' => 'integer',
        ];
    }
}
