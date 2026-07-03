<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\PriceType;
use App\Enums\PricingModel;
use App\Enums\TaxMode;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $product_id
 * @property string|null $name
 * @property PriceType $type
 * @property PricingModel $pricing_model
 * @property int|null $unit_amount
 * @property string $currency
 * @property BillingInterval|null $billing_interval
 * @property int $billing_frequency
 * @property int|null $package_size
 * @property TaxMode $tax_mode
 * @property CatalogStatus $status
 * @property int $version
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Product $product
 * @property-read Collection<int, PriceTier> $tiers
 */
#[Fillable([
    'team_id', 'product_id', 'name', 'type', 'pricing_model',
    'unit_amount', 'currency', 'billing_interval', 'billing_frequency',
    'package_size', 'tax_mode', 'status', 'version', 'custom_data',
])]
class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'price';
    }

    /**
     * Get the team this price belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the product this price belongs to.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the tiers driving this price, when its pricing model uses them.
     *
     * @return HasMany<PriceTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('tier_index');
    }

    /**
     * Scope to prices a customer would actually choose at checkout —
     * excludes the hidden zero-amount prices trial offers create for
     * themselves (see CATALOG_DESIGN.md §7.1). No schema flag needed: a
     * price is a "trial price" purely by being referenced as one.
     *
     * @param  Builder<Price>  $query
     * @return Builder<Price>
     */
    public function scopeCustomerFacing(Builder $query): Builder
    {
        return $query->whereNotIn('id', TrialOffer::query()->select('trial_price_id'));
    }

    /**
     * Format this price for the frontend — amounts converted back to major
     * currency units (see App\Actions\Catalog\CreatePrice for the reverse).
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(?TrialOffer $trial = null): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'name' => $this->name,
            'type' => $this->type,
            'pricingModel' => $this->pricing_model,
            'unitAmount' => $this->unit_amount !== null ? $this->unit_amount / 100 : null,
            'currency' => $this->currency,
            'billingInterval' => $this->billing_interval,
            'billingFrequency' => $this->billing_frequency,
            'status' => $this->status,
            'tiers' => $this->relationLoaded('tiers')
                ? $this->tiers->map(fn (PriceTier $tier) => [
                    'id' => $tier->id,
                    'tierIndex' => $tier->tier_index,
                    'upTo' => $tier->up_to,
                    'unitAmount' => $tier->unit_amount / 100,
                    'flatAmount' => $tier->flat_amount !== null ? $tier->flat_amount / 100 : null,
                ])->all()
                : [],
            'trial' => $trial ? [
                'id' => $trial->id,
                'durationAmount' => $trial->trialPrice->billing_frequency,
                'durationUnit' => $trial->trialPrice->billing_interval,
                'oncePerCustomer' => $trial->once_per_customer,
            ] : null,
        ];
    }

    /**
     * Determine if the price is archived.
     */
    public function isArchived(): bool
    {
        return $this->status === CatalogStatus::Archived;
    }

    /**
     * Whether this price has ever been referenced by a subscription.
     *
     * Subscriptions don't exist until Phase 5 — this always returns false
     * for now, but every price edit routes through here so the Phase 5
     * usage-check (see CATALOG_DESIGN.md Principle 6) has one place to land.
     */
    public function hasBeenUsed(): bool
    {
        return false;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PriceType::class,
            'pricing_model' => PricingModel::class,
            'billing_interval' => BillingInterval::class,
            'tax_mode' => TaxMode::class,
            'status' => CatalogStatus::class,
            'custom_data' => 'array',
        ];
    }
}
