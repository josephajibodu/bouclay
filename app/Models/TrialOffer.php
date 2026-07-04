<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\TrialDurationType;
use Database\Factories\TrialOfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property string $name
 * @property int $product_id
 * @property int $trial_price_id
 * @property bool $transition_to_different_product
 * @property int|null $transition_product_id
 * @property int $transition_price_id
 * @property TrialDurationType $duration_type
 * @property int|null $duration_iterations
 * @property Carbon|null $duration_ends_at
 * @property bool $once_per_customer
 * @property bool $active
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Product $product
 * @property-read Price $trialPrice
 * @property-read Product|null $transitionProduct
 * @property-read Price $transitionPrice
 */
#[Fillable([
    'team_id', 'name', 'product_id', 'trial_price_id',
    'transition_to_different_product', 'transition_product_id', 'transition_price_id',
    'duration_type', 'duration_iterations', 'duration_ends_at',
    'once_per_customer', 'active', 'custom_data',
])]
class TrialOffer extends Model
{
    /** @use HasFactory<TrialOfferFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'trial';
    }

    /**
     * Get the team this trial offer belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the product this trial offer is scoped to.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the price charged during the trial — a real catalog price, not
     * necessarily free (see CATALOG_DESIGN.md §7.1, revised).
     *
     * @return BelongsTo<Price, $this>
     */
    public function trialPrice(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'trial_price_id');
    }

    /**
     * Get the product the item transitions to when the trial ends, when
     * `transition_to_different_product` is set — otherwise equal to `product`.
     *
     * @return BelongsTo<Product, $this>
     */
    public function transitionProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'transition_product_id');
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
     * Format this trial offer for the frontend.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'name' => $this->name,
            'trialPrice' => [
                'id' => $this->trialPrice->id,
                'label' => $this->trialPrice->toPickerLabel(),
            ],
            'transitionToDifferentProduct' => $this->transition_to_different_product,
            'transitionProduct' => $this->transition_to_different_product ? [
                'id' => $this->transitionProduct->id,
                'name' => $this->transitionProduct->name,
            ] : null,
            'transitionPrice' => [
                'id' => $this->transitionPrice->id,
                'label' => $this->transitionPrice->toPickerLabel(),
            ],
            'durationIterations' => $this->duration_iterations,
            'active' => $this->active,
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
            'transition_to_different_product' => 'boolean',
            'duration_type' => TrialDurationType::class,
            'duration_ends_at' => 'datetime',
            'once_per_customer' => 'boolean',
            'active' => 'boolean',
            'custom_data' => 'array',
        ];
    }
}
