<?php

namespace App\Models;

use App\Actions\Webhooks\EmitOutboundEvent;
use App\Concerns\HasPublicId;
use App\Enums\CollectionMode;
use App\Enums\OutboundEventType;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TrialEndBehavior;
use App\States\Subscription\SubscriptionState;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $customer_id
 * @property string $type
 * @property SubscriptionStatus $status
 * @property string $currency
 * @property CollectionMode $collection_mode
 * @property int|null $payment_method_id
 * @property int|null $discount_id
 * @property string|null $billing_anchor
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $trial_ends_at
 * @property TrialEndBehavior|null $trial_end_behavior
 * @property string|null $billing_cycle_anchor_on_trial_end
 * @property Carbon|null $paused_at
 * @property Carbon|null $pause_resumes_at
 * @property Carbon|null $canceled_at
 * @property Carbon|null $ends_at
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Customer $customer
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read Discount|null $discount
 * @property-read Collection<int, DiscountRedemption> $discountRedemptions
 * @property-read Collection<int, SubscriptionItem> $items
 * @property-read Collection<int, ScheduledChange> $scheduledChanges
 * @property-read Collection<int, Invoice> $invoices
 */
#[Fillable([
    'team_id', 'customer_id', 'type', 'status', 'currency', 'collection_mode',
    'payment_method_id', 'discount_id', 'billing_anchor',
    'current_period_start', 'current_period_end', 'trial_ends_at',
    'trial_end_behavior', 'billing_cycle_anchor_on_trial_end',
    'paused_at', 'pause_resumes_at', 'canceled_at', 'ends_at', 'custom_data',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'sub';
    }

    /**
     * The hand-rolled state machine object for the current status. It owns the
     * legal transitions; call {@see apply()} to run one (SUBSCRIPTIONS_DESIGN §4).
     */
    public function state(): SubscriptionState
    {
        return $this->status->stateFor($this);
    }

    /**
     * Apply a lifecycle action through the state machine: the state guards
     * legality (throwing IllegalStateTransition when the action isn't allowed),
     * then we persist the new status plus any state-owned timestamps it set.
     *
     * @param  'activate'|'convert'|'pause'|'resume'|'cancel'|'markPastDue'|'recover'|'expire'  $action  cancel accepts an optional ends-at timestamp
     */
    public function apply(string $action, mixed ...$args): SubscriptionState
    {
        $previousStatus = $this->status;

        $next = $this->state()->{$action}(...$args);

        $this->status = $next->status();
        $this->save();

        if ($previousStatus !== $this->status) {
            $this->loadMissing(['customer', 'team']);

            app(EmitOutboundEvent::class)->handle(
                $this->team,
                OutboundEventType::SubscriptionUpdated,
                ['object' => $this->toWebhookObject()],
            );
        }

        return $next;
    }

    /**
     * Get the team this subscription belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the customer this subscription bills.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the payment method charged for this subscription, if any.
     *
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Get the discount applied to this subscription, if any.
     *
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * Get this subscription's discount redemptions — the durable record of
     * how many intervals a repeating discount has left (schema.md §7).
     *
     * @return HasMany<DiscountRedemption, $this>
     */
    public function discountRedemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /**
     * Get the subscription's line items (base plan + add-ons).
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    /**
     * Get only the still-billing items.
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function activeItems(): HasMany
    {
        return $this->items()->where('status', SubscriptionItemStatus::Active);
    }

    /**
     * Get the future cancel/pause/resume changes queued on this subscription.
     *
     * @return HasMany<ScheduledChange, $this>
     */
    public function scheduledChanges(): HasMany
    {
        return $this->hasMany(ScheduledChange::class);
    }

    /**
     * Get the invoices generated for this subscription (Phase 6+).
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The discount redemption that should still apply to this subscription's
     * next invoice, or null (schema.md §7, GAP-1). Resolves the redemption for
     * the currently-attached `discount_id` and only returns it while it has
     * billing intervals left — the durable answer to "is this discount still
     * live on cycle N?". The discount is eager-loaded for application.
     */
    public function activeDiscountRedemption(): ?DiscountRedemption
    {
        if ($this->discount_id === null) {
            return null;
        }

        $redemption = $this->discountRedemptions()
            ->where('discount_id', $this->discount_id)
            ->with('discount')
            ->latest('id')
            ->first();

        return $redemption !== null && $redemption->hasIntervalsLeft() ? $redemption : null;
    }

    /**
     * Whether the subscription is currently on a free trial.
     */
    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing
            && $this->trial_ends_at !== null;
    }

    /**
     * A short label for the plan — the first item's product name, with a
     * "+N more" hint when there are several (SUBSCRIPTIONS_DESIGN §6.2).
     */
    public function planLabel(): string
    {
        $items = $this->relationLoaded('activeItems') ? $this->activeItems : $this->activeItems()->with('product')->get();

        $first = $items->first();

        if ($first === null) {
            return '—';
        }

        $name = $first->product->name;
        $extra = $items->count() - 1;

        return $extra > 0 ? "{$name} +{$extra} more" : $name;
    }

    /**
     * Serialise for integrator webhook payloads.
     *
     * Carries the customer's entitlement codes so an integrator can gate on
     * webhooks alone (IMPLEMENTATION_V2 §V2-5), without calling back for the
     * access list on every event.
     *
     * They hang off `customer` because that is whose access it is: the union
     * across *all* their subscriptions, not just this one. That distinction is
     * what makes the payload usable — on a cancel event, a customer who still
     * holds `hd_streaming` through another subscription must not be locked
     * out, and this subscription's own grants could not tell you that.
     * Resolved after the state change is persisted, so it reflects access as
     * of this event.
     *
     * @return array<string, mixed>
     */
    public function toWebhookObject(): array
    {
        return [
            'publicId' => $this->public_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'collectionMode' => $this->collection_mode->value,
            'trialEndsAt' => $this->trial_ends_at?->toISOString(),
            'currentPeriodStart' => $this->current_period_start?->toISOString(),
            'currentPeriodEnd' => $this->current_period_end?->toISOString(),
            'canceledAt' => $this->canceled_at?->toISOString(),
            'endsAt' => $this->ends_at?->toISOString(),
            'customer' => [
                'publicId' => $this->customer->public_id,
                'email' => $this->customer->email,
                'name' => $this->customer->name,
                'entitlements' => $this->customer->entitlementCodes(),
            ],
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Serialise for the public Billing API.
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        $this->loadMissing(['customer', 'paymentMethod', 'items.product', 'items.price', 'scheduledChanges']);

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'collectionMode' => $this->collection_mode->value,
            'trialEndsAt' => $this->trial_ends_at?->toISOString(),
            'currentPeriodStart' => $this->current_period_start?->toISOString(),
            'currentPeriodEnd' => $this->current_period_end?->toISOString(),
            'canceledAt' => $this->canceled_at?->toISOString(),
            'endsAt' => $this->ends_at?->toISOString(),
            'customer' => [
                'id' => $this->customer->public_id,
                'email' => $this->customer->email,
                'name' => $this->customer->name,
            ],
            'createdAt' => $this->created_at?->toISOString(),
            'trialEndBehavior' => $this->trial_end_behavior?->value,
            'paymentMethod' => $this->paymentMethod !== null
                ? ['id' => $this->paymentMethod->public_id]
                : null,
            'items' => $this->items->map(fn (SubscriptionItem $item) => $item->toApiObject())->all(),
            'scheduledChanges' => $this->scheduledChanges
                ->whereNull('applied_at')
                ->map(fn ($change) => [
                    'action' => $change->action->value,
                    'effectiveAt' => $change->effective_at?->toISOString(),
                ])->values()->all(),
        ];
    }

    /**
     * Serialise a row for the subscriptions list (SUBSCRIPTIONS_DESIGN §6).
     *
     * @return array<string, mixed>
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'status' => $this->status->value,
            'planLabel' => $this->planLabel(),
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
            ],
            'trialEndsAt' => $this->trial_ends_at?->toISOString(),
            'currentPeriodEnd' => $this->current_period_end?->toISOString(),
            'cancelsAt' => $this->canceled_at !== null ? $this->ends_at?->toISOString() : null,
            'createdAt' => $this->created_at?->toISOString(),
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
            'status' => SubscriptionStatus::class,
            'collection_mode' => CollectionMode::class,
            'trial_end_behavior' => TrialEndBehavior::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'paused_at' => 'datetime',
            'pause_resumes_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ends_at' => 'datetime',
            'custom_data' => 'array',
        ];
    }
}
