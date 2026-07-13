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
use Illuminate\Support\Carbon;

/**
 * One priced line on a subscription — the base plan item or an add-on
 * (schema.md §6). `plan_id`/`product_id` are denormalised alongside
 * `price_id`; `trial_ends_at` is snapshotted from the price's trial config
 * at creation so a later catalog edit doesn't rewrite history;
 * `current_phase_sequence` tracks progression through `price_phases`.
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
 * @property int|null $current_phase_sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription $subscription
 * @property-read Price $price
 * @property-read Plan $plan
 * @property-read Product $product
 */
#[Fillable([
    'subscription_id', 'price_id', 'plan_id', 'product_id', 'kind',
    'quantity', 'status', 'trial_ends_at', 'current_phase_sequence',
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
     * Whether this item is still inside its snapshotted trial window.
     */
    public function isOnTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * The price actually charged for this item right now (schema.md §5/§6).
     *
     * `price_id` is the nominal "home" price the customer signed up for; when
     * the item is progressing through `price_phases` (`current_phase_sequence`
     * set), the effective charge is the current phase's `charge_price`. A
     * simple trial or a plain item never touches phases, so the home price is
     * also the effective one. Every biller (renewal, conversion, day-0)
     * resolves the amount to charge through here so phase ramps settle down
     * the one invoicing path.
     */
    public function effectiveChargePrice(): Price
    {
        if ($this->current_phase_sequence === null) {
            return $this->price;
        }

        $phase = $this->price->phases
            ->first(fn (PricePhase $phase): bool => $phase->sequence === $this->current_phase_sequence);

        return $phase?->chargePrice ?? $this->price;
    }

    /**
     * The final phase index of this item's schedule, or null when it has no
     * phases (a simple trial or a plain item).
     */
    public function lastPhaseSequence(): ?int
    {
        $last = $this->price->phases->max('sequence');

        return $last === null ? null : (int) $last;
    }

    /**
     * Whether this item still has a phase boundary ahead of it — it's threading
     * `price_phases` and hasn't reached the final (steady-state) phase yet.
     * A paid-trial ramp is mid-progress until it lands on its regular phase;
     * `subscriptions:advance-phases` owns it until then, and renewal takes over
     * once it's steady.
     */
    public function isProgressingThroughPhases(): bool
    {
        $last = $this->lastPhaseSequence();

        return $this->current_phase_sequence !== null
            && $last !== null
            && $this->current_phase_sequence < $last;
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
            'current_phase_sequence' => 'integer',
        ];
    }
}
