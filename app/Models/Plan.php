<?php

namespace App\Models;

use App\Actions\Webhooks\EmitOutboundEvent;
use App\Concerns\HasPublicId;
use App\Enums\OutboundEventType;
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
     * Mirror the column default in memory, so a freshly created model knows
     * its own status before the DB round-trips it back. Without this a
     * `created` hook sees `status = null` on any row that relied on the
     * default.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => PlanStatus::Active->value,
    ];

    /**
     * Announce catalog changes to integrators (schema.md §9). See
     * {@see Product::booted()} for why this is a model hook.
     */
    protected static function booted(): void
    {
        static::created(fn (Plan $plan) => $plan->emitWebhookEvent(OutboundEventType::PlanCreated));
        static::updated(fn (Plan $plan) => $plan->emitWebhookEvent(OutboundEventType::PlanUpdated));
    }

    /**
     * Serialise for integrator webhook payloads.
     *
     * @return array<string, mixed>
     */
    public function toWebhookObject(): array
    {
        $this->loadMissing('product');

        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
            'product' => [
                'id' => $this->product->public_id,
                'name' => $this->product->name,
            ],
            'createdAt' => $this->created_at?->toISOString(),
        ];
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
     * Serialise for the public Billing API.
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        $this->loadMissing('product');

        return [
            'id' => $this->public_id,
            'productId' => $this->product->public_id,
            'code' => $this->code,
            'name' => $this->name,
            'status' => $this->status->value,
            'customData' => $this->custom_data,
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
            'status' => PlanStatus::class,
            'custom_data' => 'array',
        ];
    }
}
