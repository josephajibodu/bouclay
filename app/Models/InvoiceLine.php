<?php

namespace App\Models;

use App\Enums\InvoiceLineKind;
use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One itemised line on an invoice (schema.md §7).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int|null $subscription_item_id
 * @property int|null $price_id
 * @property int|null $product_id
 * @property InvoiceLineKind $kind
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount
 * @property int $subtotal
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property bool $proration
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Invoice $invoice
 * @property-read SubscriptionItem|null $subscriptionItem
 * @property-read Price|null $price
 * @property-read Product|null $product
 */
#[Fillable([
    'invoice_id', 'subscription_item_id', 'price_id', 'product_id', 'kind',
    'description', 'quantity', 'unit_amount', 'subtotal', 'discount_amount',
    'tax_amount', 'total', 'period_start', 'period_end', 'proration',
])]
class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    /**
     * Get the invoice this line belongs to.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the subscription item this line was billed for, if any.
     *
     * @return BelongsTo<SubscriptionItem, $this>
     */
    public function subscriptionItem(): BelongsTo
    {
        return $this->belongsTo(SubscriptionItem::class);
    }

    /**
     * Get the price this line was billed at, if any.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }

    /**
     * Get the product this line was billed for, if any.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceLineKind::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'proration' => 'boolean',
        ];
    }
}
