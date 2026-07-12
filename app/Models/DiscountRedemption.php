<?php

namespace App\Models;

use Database\Factories\DiscountRedemptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One discount applied to one subscription (schema.md §7).
 *
 * `remaining_intervals` is the durable answer to "is WELCOME20 still live on
 * cycle N?" (GAP-1): snapshotted at redemption from the discount's duration,
 * the renewal worker applies the discount only while it's null or > 0,
 * decrementing by 1 each cycle it applies and stamping `last_applied_at`.
 *
 * @property int $id
 * @property int $discount_id
 * @property int $subscription_id
 * @property int $customer_id
 * @property int|null $remaining_intervals
 * @property Carbon $applied_at
 * @property Carbon|null $last_applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Discount $discount
 * @property-read Subscription $subscription
 * @property-read Customer $customer
 */
#[Fillable([
    'discount_id', 'subscription_id', 'customer_id',
    'remaining_intervals', 'applied_at', 'last_applied_at',
])]
class DiscountRedemption extends Model
{
    /** @use HasFactory<DiscountRedemptionFactory> */
    use HasFactory;

    /**
     * Get the discount that was redeemed.
     *
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * Get the subscription the discount applies to.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the customer who redeemed the discount.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remaining_intervals' => 'integer',
            'applied_at' => 'datetime',
            'last_applied_at' => 'datetime',
        ];
    }
}
