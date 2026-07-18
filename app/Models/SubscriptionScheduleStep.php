<?php

namespace App\Models;

use Database\Factories\SubscriptionScheduleStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The resolved, customer-specific counterpart of a {@see PricingJourneyStep}
 * (schema.md §5): durations become absolute `starts_at`/`ends_at` dates at
 * copy time, so the advance-schedule worker never re-derives boundaries
 * from interval arithmetic — it just reads `ends_at`. `ends_at` null marks
 * the terminal ("forever") step.
 *
 * @property int $id
 * @property int $schedule_id
 * @property int $sequence
 * @property int $price_id
 * @property int $quantity
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SubscriptionSchedule $schedule
 * @property-read Price $price
 */
#[Fillable(['schedule_id', 'sequence', 'price_id', 'quantity', 'starts_at', 'ends_at'])]
class SubscriptionScheduleStep extends Model
{
    /** @use HasFactory<SubscriptionScheduleStepFactory> */
    use HasFactory;

    /**
     * Get the schedule this step belongs to.
     *
     * @return BelongsTo<SubscriptionSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(SubscriptionSchedule::class, 'schedule_id');
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
        return $this->ends_at === null;
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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
