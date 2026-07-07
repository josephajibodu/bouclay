<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\SubscriptionItemStatus;
use App\Enums\SubscriptionItemTrialStatus;
use Database\Factories\SubscriptionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $subscription_id
 * @property int $price_id
 * @property int $product_id
 * @property int $quantity
 * @property SubscriptionItemStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription $subscription
 * @property-read Price $price
 * @property-read Product $product
 * @property-read SubscriptionItemTrial|null $currentTrial
 */
#[Fillable([
    'subscription_id', 'price_id', 'product_id', 'quantity', 'status',
])]
class SubscriptionItem extends Model
{
    /** @use HasFactory<SubscriptionItemFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'si';
    }

    /**
     * Get the subscription this item belongs to.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the price this item bills.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /**
     * Get the product this item's price belongs to.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the active trial applied to this item, if any — the Stripe
     * `items[].current_trial` (schema.md §5).
     *
     * @return HasOne<SubscriptionItemTrial, $this>
     */
    public function currentTrial(): HasOne
    {
        return $this->hasOne(SubscriptionItemTrial::class)
            ->where('status', SubscriptionItemTrialStatus::Active);
    }

    /**
     * Whether this item is a trial line (carries an active trial).
     */
    public function isTrial(): bool
    {
        return $this->currentTrial !== null;
    }

    /**
     * Serialise this item for the subscription hub (SUBSCRIPTIONS_DESIGN §11.1).
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        $trial = $this->currentTrial;

        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ],
            'price' => [
                'id' => $this->price->id,
                'label' => $this->price->toPickerLabel(),
                'unitAmount' => $this->price->unit_amount !== null ? $this->price->unit_amount / 100 : null,
                'currency' => $this->price->currency,
                'billingInterval' => $this->price->billing_interval?->value,
            ],
            'quantity' => $this->quantity,
            'status' => $this->status->value,
            'trial' => $trial?->toDashboardArray(),
        ];
    }

    /**
     * Serialise for the public Billing API.
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        $this->loadMissing(['product', 'price']);

        return [
            'publicId' => $this->public_id,
            'productId' => $this->product->public_id,
            'priceId' => $this->price->public_id,
            'quantity' => $this->quantity,
            'status' => $this->status->value,
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
            'status' => SubscriptionItemStatus::class,
            'quantity' => 'integer',
        ];
    }
}
