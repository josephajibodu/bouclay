<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\SubscriptionItemTrialStatus;
use App\Enums\TrialDurationType;
use Database\Factories\SubscriptionItemTrialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $subscription_item_id
 * @property int|null $trial_offer_id
 * @property int $customer_id
 * @property int $trial_price_id
 * @property int $transition_price_id
 * @property TrialDurationType $duration_type
 * @property int|null $duration_iterations
 * @property Carbon|null $duration_ends_at
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property SubscriptionItemTrialStatus $status
 * @property Carbon|null $converted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read SubscriptionItem $subscriptionItem
 * @property-read TrialOffer|null $trialOffer
 * @property-read Customer $customer
 * @property-read Price $trialPrice
 * @property-read Price $transitionPrice
 */
#[Fillable([
    'team_id', 'subscription_item_id', 'trial_offer_id', 'customer_id',
    'trial_price_id', 'transition_price_id', 'duration_type',
    'duration_iterations', 'duration_ends_at', 'starts_at', 'ends_at',
    'status', 'converted_at',
])]
class SubscriptionItemTrial extends Model
{
    /** @use HasFactory<SubscriptionItemTrialFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'sit';
    }

    /**
     * Get the team this trial belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the item this trial is applied to.
     *
     * @return BelongsTo<SubscriptionItem, $this>
     */
    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class);
    }

    /**
     * Get the catalog offer this trial was snapshotted from, if any.
     *
     * @return BelongsTo<TrialOffer, $this>
     */
    public function trialOffer(): BelongsTo
    {
        return $this->belongsTo(TrialOffer::class);
    }

    /**
     * Get the customer this trial belongs to (denormalised for once-per-customer).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the price charged during the trial.
     *
     * @return BelongsTo<Price, $this>
     */
    public function trialPrice(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'trial_price_id');
    }

    /**
     * Get the price the item transitions to when the trial ends.
     *
     * @return BelongsTo<Price, $this>
     */
    public function transitionPrice(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'transition_price_id');
    }

    /**
     * Whether the trial price is free (schema.md §5 — free vs paid is inferred
     * from the trial price, not a flag).
     */
    public function isFree(): bool
    {
        return ($this->trialPrice->unit_amount ?? 0) === 0;
    }

    /**
     * Serialise for the subscription hub (SUBSCRIPTIONS_DESIGN §10, §11).
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'isFree' => $this->isFree(),
            'durationIterations' => $this->duration_iterations,
            'startsAt' => $this->starts_at->toISOString(),
            'endsAt' => $this->ends_at->toISOString(),
            'status' => $this->status->value,
            'transitionPrice' => [
                'id' => $this->transitionPrice->id,
                'label' => $this->transitionPrice->toPickerLabel(),
                'unitAmount' => $this->transitionPrice->unit_amount !== null ? $this->transitionPrice->unit_amount / 100 : null,
                'currency' => $this->transitionPrice->currency,
            ],
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
            'duration_type' => TrialDurationType::class,
            'duration_ends_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => SubscriptionItemTrialStatus::class,
            'converted_at' => 'datetime',
        ];
    }
}
