<?php

namespace App\Models;

use App\Enums\BillingInterval;
use Database\Factories\PricingJourneyStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One ordered step within a {@see PricingJourney} (schema.md §3). Always
 * charges a real `prices` row — never an inline/ad hoc amount — so pricing
 * always traces back to a single defined Price. `duration_interval`/
 * `duration_count` both null marks the terminal ("forever") step, always
 * the last step in a journey by construction.
 *
 * @property int $id
 * @property int $price_phases_id
 * @property int $sequence
 * @property int $price_id
 * @property int $quantity
 * @property BillingInterval|null $duration_interval
 * @property int|null $duration_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PricingJourney $journey
 * @property-read Price $price
 */
#[Fillable(['price_phases_id', 'sequence', 'price_id', 'quantity', 'duration_interval', 'duration_count'])]
class PricingJourneyStep extends Model
{
    /** @use HasFactory<PricingJourneyStepFactory> */
    use HasFactory;

    protected $table = 'price_phase_steps';

    /**
     * Get the journey this step belongs to.
     *
     * @return BelongsTo<PricingJourney, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(PricingJourney::class, 'price_phases_id');
    }

    /**
     * Get the price charged during this step.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /**
     * Whether this is the terminal ("forever") step.
     */
    public function isTerminal(): bool
    {
        return $this->duration_interval === null || $this->duration_count === null;
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
            'quantity' => 'integer',
            'duration_interval' => BillingInterval::class,
            'duration_count' => 'integer',
        ];
    }
}
