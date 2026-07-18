<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\SubscriptionItemKind;
use App\Enums\SubscriptionItemStatus;
use Database\Factories\SubscriptionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One priced line on a subscription — the base plan item or an add-on
 * (schema.md §6). `price_id`/`plan_id`/`product_id` are live current
 * values — a plan-item on a {@see SubscriptionSchedule} has them re-anchored
 * to the schedule's current step at every boundary, not fixed at signup.
 * `trial_ends_at` is snapshotted from the price's trial config (or the
 * schedule's step-0 window) at creation so a later catalog edit doesn't
 * rewrite history. `current_schedule_step_id` tracks progression through a
 * {@see SubscriptionSchedule} — null unless this item is on one.
 *
 * @property int $id
 * @property string $public_id
 * @property int $subscription_id
 * @property int $price_id
 * @property int $plan_id
 * @property int $product_id
 * @property SubscriptionItemKind $kind
 * @property int $quantity
 * @property SubscriptionItemStatus $status
 * @property Carbon|null $trial_ends_at
 * @property int|null $current_schedule_step_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription $subscription
 * @property-read Price $price
 * @property-read Plan $plan
 * @property-read Product $product
 * @property-read SubscriptionScheduleStep|null $currentScheduleStep
 * @property-read SubscriptionSchedule|null $schedule
 */
#[Fillable([
    'subscription_id', 'price_id', 'plan_id', 'product_id', 'kind',
    'quantity', 'status', 'trial_ends_at', 'current_schedule_step_id',
])]
class SubscriptionItem extends Model
{
    /** @use HasFactory<SubscriptionItemFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'si';
    }

    /**
     * Get the subscription this item belongs to.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the price this item bills.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /**
     * Get the plan this item's price is a variant of (denormalised).
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the product this item's price belongs to (denormalised).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the schedule step this item is currently on, when it's on one.
     *
     * @return BelongsTo<SubscriptionScheduleStep, $this>
     */
    public function currentScheduleStep(): BelongsTo
    {
        return $this->belongsTo(SubscriptionScheduleStep::class, 'current_schedule_step_id');
    }

    /**
     * Get the schedule driving this item, when one exists (v1: at most one
     * per item — a plan item created flat, or created through a journey/ad
     * hoc schedule that hasn't been attempted again since).
     *
     * @return HasOne<SubscriptionSchedule, $this>
     */
    public function schedule(): HasOne
    {
        return $this->hasOne(SubscriptionSchedule::class, 'subscription_item_id');
    }

    /**
     * Whether this item is still inside its snapshotted trial window.
     */
    public function isOnTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * Whether this item still has a schedule boundary ahead of it — it's on
     * an active {@see SubscriptionSchedule} and hasn't reached the terminal
     * (steady-state) step yet. `subscriptions:advance-schedule` owns it
     * until then, and renewal takes over once it's steady (schema.md §5).
     */
    public function isOnSchedule(): bool
    {
        return $this->current_schedule_step_id !== null
            && $this->currentScheduleStep !== null
            && ! $this->currentScheduleStep->isTerminal();
    }

    /**
     * Serialise this item for the subscription hub (SUBSCRIPTIONS_DESIGN §11.1).
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'kind' => $this->kind->value,
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ],
            'plan' => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
            ],
            'price' => [
                'id' => $this->price->id,
                'label' => $this->price->toPickerLabel(),
                'unitAmount' => $this->price->unit_amount !== null ? $this->price->unit_amount / 100 : null,
                'currency' => $this->price->currency,
                'billingInterval' => $this->price->billing_interval?->value,
            ],
            'quantity' => $this->quantity,
            'status' => $this->status->value,
            'trialEndsAt' => $this->trial_ends_at?->toISOString(),
            'schedule' => $this->relationLoaded('schedule') && $this->schedule !== null
                ? $this->schedule->toDashboardArray($this->current_schedule_step_id)
                : null,
        ];
    }

    /**
     * Serialise for the public Billing API.
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        $this->loadMissing(['product', 'plan', 'price']);

        return [
            'id' => $this->public_id,
            'productId' => $this->product->public_id,
            'planId' => $this->plan->public_id,
            'priceId' => $this->price->public_id,
            'kind' => $this->kind->value,
            'quantity' => $this->quantity,
            'status' => $this->status->value,
            'trialEndsAt' => $this->trial_ends_at?->toISOString(),
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
            'kind' => SubscriptionItemKind::class,
            'status' => SubscriptionItemStatus::class,
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
        ];
    }
}
