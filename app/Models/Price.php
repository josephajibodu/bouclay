<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\PriceType;
use App\Enums\PricingModel;
use App\Enums\TaxMode;
use App\Support\Api\ApiMoney;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property-read PaymentLink|null $paymentLink
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
     * Get the durable hosted checkout link for this price, when one exists.
     *
     * @return HasOne<PaymentLink, $this>
     */
    public function paymentLink(): HasOne
    {
        return $this->hasOne(PaymentLink::class);
    }

    /**
     * Format this price for the frontend — amounts converted back to major
     * currency units (see App\Actions\Catalog\CreatePrice for the reverse).
     * Trial involvement is no longer embedded here — a price is a normal,
     * visible catalog price whether or not a trial references it (see
     * CATALOG_DESIGN.md §7.1, revised); the frontend cross-references the
     * separate `trials` list against price ids when it needs to show that.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
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
            'taxMode' => $this->tax_mode,
            'status' => $this->status,
            'hasBeenUsed' => $this->hasBeenUsed(),
            'customData' => $this->custom_data,
            'paymentLink' => $this->relationLoaded('paymentLink') && $this->paymentLink !== null
                ? [
                    'id' => $this->paymentLink->public_id,
                    'url' => $this->paymentLink->url(),
                    'priceLabel' => $this->toPickerLabel(),
                ]
                : null,
            'createdAt' => $this->created_at?->toISOString(),
            'tiers' => $this->relationLoaded('tiers')
                ? $this->tiers->map(fn (PriceTier $tier) => [
                    'id' => $tier->id,
                    'tierIndex' => $tier->tier_index,
                    'upTo' => $tier->up_to,
                    'unitAmount' => $tier->unit_amount / 100,
                    'flatAmount' => $tier->flat_amount !== null ? $tier->flat_amount / 100 : null,
                ])->all()
                : [],
        ];
    }

    /**
     * Serialise for the public Billing API (amounts in major units).
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        $this->loadMissing(['product', 'tiers']);

        return [
            'id' => $this->public_id,
            'productId' => $this->product->public_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'pricingModel' => $this->pricing_model->value,
            'unitAmount' => ApiMoney::toMajorUnits($this->unit_amount),
            'currency' => $this->currency,
            'billingInterval' => $this->billing_interval?->value,
            'billingFrequency' => $this->billing_frequency,
            'taxMode' => $this->tax_mode->value,
            'status' => $this->status->value,
            'customData' => $this->custom_data,
            'createdAt' => $this->created_at?->toISOString(),
            'tiers' => $this->tiers->map(fn (PriceTier $tier) => [
                'tierIndex' => $tier->tier_index,
                'upTo' => $tier->up_to,
                'unitAmount' => ApiMoney::toMajorUnits($tier->unit_amount),
                'flatAmount' => ApiMoney::toMajorUnits($tier->flat_amount),
            ])->all(),
        ];
    }

    /**
     * Format this price as a short label for pickers (trial price /
     * transition price dropdowns), e.g. "Monthly — NGN 15,000/mo".
     */
    public function toPickerLabel(): string
    {
        if ($this->name) {
            return $this->name;
        }

        if ($this->unit_amount === null || $this->billing_interval === null) {
            return 'Custom pricing';
        }

        $amount = number_format($this->unit_amount / 100, 2);
        $frequency = $this->billing_frequency > 1 ? "{$this->billing_frequency} " : '';

        return "{$this->currency} {$amount} / every {$frequency}{$this->billing_interval->value}";
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
