<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\DiscountDuration;
use App\Enums\DiscountType;
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
