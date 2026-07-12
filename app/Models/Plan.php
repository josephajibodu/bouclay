<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\PlanStatus;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The named tier a customer actually picks — "Premium" (schema.md §3).
 * Deliberately thin: identity and lifecycle only. Cadence, amounts, and
 * trial config all vary per billable variant and live on `prices`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $product_id
 * @property string|null $code
 * @property string $name
 * @property PlanStatus $status
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Product $product
 * @property-read Collection<int, Price> $prices
 * @property-read Collection<int, EntitlementGrant> $entitlementGrants
 */
#[Fillable(['team_id', 'product_id', 'code', 'name', 'status', 'custom_data'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'plan';
    }

    /**
     * Get the team this plan belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the product this plan is a tier of.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the billable variants of this plan.
     *
     * @return HasMany<Price, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /**
     * Get the entitlement grants this plan confers (morph alias `plan`).
     *
     * @return MorphMany<EntitlementGrant, $this>
     */
    public function entitlementGrants(): MorphMany
    {
        return $this->morphMany(EntitlementGrant::class, 'grantor');
    }

    /**
     * Whether prices under this plan may be attached to new subscriptions —
     * the draft/archived-plan purchasability rule (schema.md §3).
     */
    public function isPurchasable(): bool
    {
        return $this->status === PlanStatus::Active;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'custom_data' => 'array',
        ];
    }
}
