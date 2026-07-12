<?php

namespace App\Models;

use Database\Factories\PriceTrialRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The one piece of trial state worth its own durable row: enforcing
 * `trial_once_per_customer` (schema.md §3). Always query by `team_id`
 * directly — anti-abuse is where "never trust a join to infer the tenant"
 * matters most.
 *
 * @property int $id
 * @property int $team_id
 * @property int $price_id
 * @property int $customer_id
 * @property int $subscription_item_id
 * @property Carbon $redeemed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Price $price
 * @property-read Customer $customer
 * @property-read SubscriptionItem $subscriptionItem
 */
#[Fillable(['team_id', 'price_id', 'customer_id', 'subscription_item_id', 'redeemed_at'])]
class PriceTrialRedemption extends Model
{
    /** @use HasFactory<PriceTrialRedemptionFactory> */
    use HasFactory;

    /**
     * Get the team this redemption belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the trial-bearing price that was redeemed.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /**
     * Get the customer who redeemed the trial.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the subscription item the trial started on.
     *
     * @return BelongsTo<SubscriptionItem, $this>
     */
    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }
}
