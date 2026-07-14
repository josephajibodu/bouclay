<?php

namespace App\Models;

use App\Actions\Invoicing\CreateInvoice;
use App\Concerns\HasPublicId;
use App\Enums\DiscountDuration;
use App\Enums\DiscountType;
use App\Support\Api\ApiMoney;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A percentage or flat reduction applied to subscriptions (schema.md §7).
 *
 * Eligibility: when `eligible_price_ids` is set it is the COMPLETE,
 * authoritative list and `eligible_plan_ids` is ignored — the two are never
 * combined. When null, falls back to `eligible_plan_ids` (or everything).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property string|null $code
 * @property DiscountType $type
 * @property int|null $amount
 * @property string|null $percentage
 * @property string|null $currency
 * @property DiscountDuration $duration
 * @property int|null $duration_in_intervals
 * @property int|null $max_redemptions
 * @property int $times_redeemed
 * @property array<int, int>|null $eligible_plan_ids
 * @property array<int, int>|null $eligible_price_ids
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, DiscountRedemption> $redemptions
 */
#[Fillable([
    'team_id', 'code', 'type', 'amount', 'percentage', 'currency',
    'duration', 'duration_in_intervals', 'max_redemptions', 'times_redeemed',
    'eligible_plan_ids', 'eligible_price_ids', 'starts_at', 'expires_at', 'active',
])]
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'disc';
    }

    /**
     * Get the team this discount belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get this discount's redemptions.
     *
     * @return HasMany<DiscountRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /**
     * The `remaining_intervals` value a fresh redemption starts with —
     * once → 1, repeating → duration_in_intervals, forever → null
     * (never decrements). See discount_redemptions (schema.md §7, GAP-1).
     */
    public function initialRemainingIntervals(): ?int
    {
        return match ($this->duration) {
            DiscountDuration::Once => 1,
            DiscountDuration::Repeating => $this->duration_in_intervals,
            DiscountDuration::Forever => null,
        };
    }

    /**
     * Whether this discount can be redeemed at all right now, independent of
     * any subscription — active, inside its start/expiry window, and under its
     * global redemption cap (schema.md §7).
     */
    public function isLive(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        if (! $this->active) {
            return false;
        }

        if ($this->starts_at !== null && $at->lt($this->starts_at)) {
            return false;
        }

        if ($this->expires_at !== null && $at->gt($this->expires_at)) {
            return false;
        }

        if ($this->max_redemptions !== null && $this->times_redeemed >= $this->max_redemptions) {
            return false;
        }

        return true;
    }

    /**
     * Whether a subscription qualifies to redeem this discount (schema.md §7).
     *
     * Eligibility is a *gate*, not a per-line filter: `eligible_price_ids`
     * wins outright when set (the subscription must carry one of those prices),
     * else `eligible_plan_ids` (one of those plans), else everything qualifies.
     * Application is whole-invoice once redeemed — the "monthly-only, not
     * yearly" case holds because a yearly price lives in a *separate*
     * subscription (single-cadence constraint, GAP-5), so the discount is
     * simply never redeemable there.
     *
     * @param  Collection<int, SubscriptionItem>  $items
     */
    public function isRedeemableBySubscriptionItems(Collection $items, string $currency): bool
    {
        if (! $this->isLive()) {
            return false;
        }

        // A flat discount only applies in its own currency.
        if ($this->type === DiscountType::Flat && $this->currency !== null && $this->currency !== $currency) {
            return false;
        }

        if ($this->eligible_price_ids !== null) {
            return $items->contains(fn (SubscriptionItem $item): bool => in_array($item->price_id, $this->eligible_price_ids, true));
        }

        if ($this->eligible_plan_ids !== null) {
            return $items->contains(fn (SubscriptionItem $item): bool => in_array($item->plan_id, $this->eligible_plan_ids, true));
        }

        return true;
    }

    /**
     * The discount to knock off a single billable line of the given subtotal
     * (minor units). Percentage is per-line; a flat discount is allocated
     * across lines by {@see CreateInvoice}, so this
     * returns 0 for flat here.
     */
    public function amountForLineSubtotal(int $subtotal): int
    {
        if ($this->type !== DiscountType::Percentage || $this->percentage === null) {
            return 0;
        }

        return (int) round($subtotal * ((float) $this->percentage) / 100);
    }

    /**
     * A short human summary of the reduction and how long it lasts, e.g.
     * "20% off · 3 months" or "NGN 1,000.00 off · once".
     */
    public function summaryLabel(): string
    {
        $magnitude = $this->type === DiscountType::Percentage
            ? rtrim(rtrim((string) $this->percentage, '0'), '.').'%'
            : ($this->currency ?? '').' '.number_format((int) ($this->amount ?? 0) / 100, 2);

        $duration = match ($this->duration) {
            DiscountDuration::Once => 'once',
            DiscountDuration::Forever => 'forever',
            DiscountDuration::Repeating => $this->duration_in_intervals === 1
                ? '1 interval'
                : "{$this->duration_in_intervals} intervals",
        };

        return trim($magnitude).' off · '.$duration;
    }

    /**
     * Serialise for the dashboard discounts list + edit drawer (amounts in
     * major units, mirroring {@see Price::toCatalogArray()}).
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'code' => $this->code,
            'type' => $this->type->value,
            'amount' => $this->amount !== null ? $this->amount / 100 : null,
            'percentage' => $this->percentage !== null ? (float) $this->percentage : null,
            'currency' => $this->currency,
            'duration' => $this->duration->value,
            'durationInIntervals' => $this->duration_in_intervals,
            'maxRedemptions' => $this->max_redemptions,
            'timesRedeemed' => $this->times_redeemed,
            'eligiblePlanIds' => $this->eligible_plan_ids,
            'eligiblePriceIds' => $this->eligible_price_ids,
            'startsAt' => $this->starts_at?->toISOString(),
            'expiresAt' => $this->expires_at?->toISOString(),
            'active' => $this->active,
            'summary' => $this->summaryLabel(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Serialise for the public Billing API (amounts in major units).
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'type' => $this->type->value,
            'amount' => ApiMoney::toMajorUnits($this->amount),
            'percentage' => $this->percentage !== null ? (float) $this->percentage : null,
            'currency' => $this->currency,
            'duration' => $this->duration->value,
            'durationInIntervals' => $this->duration_in_intervals,
            'maxRedemptions' => $this->max_redemptions,
            'timesRedeemed' => $this->times_redeemed,
            'active' => $this->active,
            'startsAt' => $this->starts_at?->toISOString(),
            'expiresAt' => $this->expires_at?->toISOString(),
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
            'type' => DiscountType::class,
            'duration' => DiscountDuration::class,
            'amount' => 'integer',
            'percentage' => 'decimal:2',
            'duration_in_intervals' => 'integer',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'eligible_plan_ids' => 'array',
            'eligible_price_ids' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'active' => 'boolean',
        ];
    }
}
