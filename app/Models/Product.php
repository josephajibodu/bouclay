<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\CatalogStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property string $name
 * @property string|null $description
 * @property string|null $category
 * @property string|null $image_url
 * @property string|null $website_url
 * @property CatalogStatus $status
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read Collection<int, Price> $prices
 * @property-read Collection<int, TrialOffer> $trialOffers
 */
#[Fillable(['team_id', 'name', 'description', 'category', 'image_url', 'website_url', 'status', 'custom_data'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'prod';
    }

    /**
     * Get the team this product belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get all prices defined for this product.
     *
     * @return HasMany<Price, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /**
     * Get all trial offers defined for this product.
     *
     * @return HasMany<TrialOffer, $this>
     */
    public function trialOffers(): HasMany
    {
        return $this->hasMany(TrialOffer::class);
    }

    /**
     * Determine if the product is archived.
     */
    public function isArchived(): bool
    {
        return $this->status === CatalogStatus::Archived;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'custom_data' => 'array',
        ];
    }

    /**
     * Serialise for the public Billing API.
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->trashed()
                ? CatalogStatus::Archived->value
                : ($this->status?->value ?? CatalogStatus::Active->value),
            'customData' => $this->custom_data,
            'createdAt' => $this->created_at?->toISOString(),
            'archivedAt' => $this->deleted_at?->toISOString(),
        ];
    }
}
