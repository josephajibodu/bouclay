<?php

namespace App\Models;

use App\Actions\Webhooks\EmitOutboundEvent;
use App\Concerns\HasPublicId;
use App\Enums\CatalogStatus;
use App\Enums\OutboundEventType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
 * @property-read Collection<int, Plan> $plans
 * @property-read Collection<int, Price> $prices
 * @property-read Collection<int, PricingJourney> $pricingJourneys
 * @property-read Collection<int, EntitlementGrant> $entitlementGrants
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
     * Mirror the column default in memory, so a freshly created model knows
     * its own status before the DB round-trips it back. Without this a
     * `created` hook sees `status = null` on any row that relied on the
     * default.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => CatalogStatus::Active->value,
    ];

    /**
     * Announce catalog changes to integrators (schema.md §9).
     *
     * A model hook rather than an emission per controller: products are
     * written from the dashboard and the API, and the catalog is only honest
     * if every one of those paths is covered. There is no such thing as an
     * "internal" product write.
     */
    protected static function booted(): void
    {
        static::created(fn (Product $product) => $product->emitWebhookEvent(OutboundEventType::ProductCreated));
        static::updated(fn (Product $product) => $product->emitWebhookEvent(OutboundEventType::ProductUpdated));
    }

    /**
     * Serialise for integrator webhook payloads.
     *
     * @return array<string, mixed>
     */
    public function toWebhookObject(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->publicStatus(),
            'websiteUrl' => $this->website_url,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * The status integrators see. Archiving a product is a soft delete, so
     * it's derived rather than stored — and the webhook and API payloads must
     * never disagree about it.
     */
    private function publicStatus(): string
    {
        return $this->trashed() ? CatalogStatus::Archived->value : $this->status->value;
    }

    private function emitWebhookEvent(OutboundEventType $type): void
    {
        $this->loadMissing('team');

        app(EmitOutboundEvent::class)->handle(
            $this->team,
            $type,
            ['object' => $this->toWebhookObject()],
        );
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
     * Get the named tiers defined under this product (schema.md §3).
     *
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Get all prices defined for this product — plan variants and
     * plan-less one-time prices alike (product_id is denormalised on
     * every price).
     *
     * @return HasMany<Price, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /**
     * Get the Pricing Journeys authored for this product (schema.md §3) —
     * every journey is scoped to exactly one product, Plan-agnostic within it.
     *
     * @return HasMany<PricingJourney, $this>
     */
    public function pricingJourneys(): HasMany
    {
        return $this->hasMany(PricingJourney::class, 'product_id');
    }

    /**
     * Get the entitlement grants this product confers (morph alias
     * `product`).
     *
     * @return MorphMany<EntitlementGrant, $this>
     */
    public function entitlementGrants(): MorphMany
    {
        return $this->morphMany(EntitlementGrant::class, 'grantor');
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
            'status' => $this->publicStatus(),
            'customData' => $this->custom_data,
            'createdAt' => $this->created_at?->toISOString(),
            'archivedAt' => $this->deleted_at?->toISOString(),
        ];
    }
}
