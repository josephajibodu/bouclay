<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\ScheduleEndBehavior;
use App\Enums\SubscriptionScheduleStatus;
use Database\Factories\SubscriptionScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer-owned copy of a {@see PricingJourney} (or an ad hoc, journey-
 * less step sequence), forked off the moment a subscription is created
 * through it (schema.md §5) — the same snapshot pattern already used for
 * invoices. From this point on, editing the source journey never touches
 * this row, and editing this row never touches the journey or any other
 * customer.
 *
 * `price_phases_id` is kept as a non-authoritative reference for reporting
 * only — no billing, invoicing, or dunning logic may read from it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $subscription_id
 * @property int $subscription_item_id
 * @property int|null $price_phases_id
 * @property ScheduleEndBehavior $end_behavior
 * @property SubscriptionScheduleStatus $status
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription $subscription
 * @property-read SubscriptionItem $subscriptionItem
 * @property-read PricingJourney|null $journey
 * @property-read Collection<int, SubscriptionScheduleStep> $steps
 */
#[Fillable(['subscription_id', 'subscription_item_id', 'price_phases_id', 'end_behavior', 'status', 'completed_at'])]
class SubscriptionSchedule extends Model
{
    /** @use HasFactory<SubscriptionScheduleFactory> */
    use HasFactory, HasPublicId;

    public function publicIdPrefix(): string
    {
        return 'sched';
    }

    /**
     * Get the subscription this schedule belongs to.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the specific plan item this schedule drives.
     *
     * @return BelongsTo<SubscriptionItem, $this>
     */
    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class);
    }

    /**
     * Get the journey this schedule was copied from, when it came from one
     * — informational only, never authoritative for billing.
     *
     * @return BelongsTo<PricingJourney, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(PricingJourney::class, 'price_phases_id');
    }

    /**
     * Get this schedule's resolved, ordered steps.
     *
     * @return HasMany<SubscriptionScheduleStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SubscriptionScheduleStep::class, 'schedule_id')->orderBy('sequence');
    }

    /**
     * Whether this schedule is still stepping through phases — false once
     * it's reached its terminal step and finalized (`completed`/`canceled`).
     */
    public function isActive(): bool
    {
        return $this->status === SubscriptionScheduleStatus::Active;
    }

    /**
     * Format this schedule for the subscription detail hub's stepper
     * (SUBSCRIPTIONS_DESIGN §9.3). Requires `steps.price` loaded.
     * `currentStepId` is passed in by the caller (the driving
     * {@see SubscriptionItem} already has it, avoiding a redundant lookup
     * back through this schedule's own `subscriptionItem` relation).
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(?int $currentStepId): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'endBehavior' => $this->end_behavior->value,
            'status' => $this->status->value,
            'currentStepId' => $currentStepId,
            'steps' => $this->steps->map(fn (SubscriptionScheduleStep $step) => [
                'id' => $step->id,
                'sequence' => $step->sequence,
                'priceLabel' => $step->price->toPickerLabel(),
                'unitAmount' => $step->price->unit_amount !== null ? $step->price->unit_amount / 100 : null,
                'currency' => $step->price->currency,
                'startsAt' => $step->starts_at->toISOString(),
                'endsAt' => $step->ends_at?->toISOString(),
                'isTerminal' => $step->isTerminal(),
            ])->all(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'end_behavior' => ScheduleEndBehavior::class,
            'status' => SubscriptionScheduleStatus::class,
            'completed_at' => 'datetime',
        ];
    }
}
