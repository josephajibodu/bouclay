<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A frozen legal document — numbered, with a full money breakdown
 * (schema.md §7). Bouclay's "Transaction" list (Paddle's word) is a view
 * over `payments` joined back to the invoice they settle.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $customer_id
 * @property int|null $subscription_id
 * @property string|null $number
 * @property InvoiceStatus $status
 * @property InvoiceBillingReason $billing_reason
 * @property CollectionMode $collection_mode
 * @property string $currency
 * @property int $subtotal
 * @property int $discount_total
 * @property int $tax_total
 * @property int $total
 * @property int $amount_paid
 * @property int $amount_due
 * @property array<string, mixed>|null $billing_address
 * @property array<string, mixed>|null $customer_snapshot
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $due_at
 * @property Carbon|null $finalized_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Customer $customer
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, Payment> $payments
 */
#[Fillable([
    'team_id', 'customer_id', 'subscription_id', 'number', 'status',
    'billing_reason', 'collection_mode', 'currency', 'subtotal',
    'discount_total', 'tax_total', 'total', 'amount_paid', 'amount_due',
    'billing_address', 'customer_snapshot', 'period_start', 'period_end',
    'due_at', 'finalized_at', 'paid_at', 'voided_at', 'custom_data',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'inv';
    }

    /**
     * Get the team this invoice belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the customer this invoice bills.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the subscription this invoice was generated for, if any — null for
     * a one-off transaction.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get this invoice's line items.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Get every charge attempt made against this invoice.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Mark this invoice paid in full by a successful payment.
     */
    public function markPaid(Payment $payment): void
    {
        $this->forceFill([
            'status' => InvoiceStatus::Paid,
            'amount_paid' => $this->total,
            'amount_due' => 0,
            'paid_at' => $payment->processed_at ?? now(),
        ])->save();
    }

    /**
     * Record a failed charge attempt without changing the invoice's own
     * status — it stays open for a retry or a different payment method.
     */
    public function recordFailedAttempt(): void
    {
        $this->touch();
    }

    /**
     * Serialise a row for the Transactions-adjacent invoice context (customer
     * hub, subscription hub).
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'total' => $this->total,
            'amountDue' => $this->amount_due,
            'dueAt' => $this->due_at?->toISOString(),
            'paidAt' => $this->paid_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Serialise a row for the global Transactions list — invoice-centric
     * (Paddle's own "Transactions" is a list of invoices, not raw charge
     * attempts), so a manually-billed or not-yet-charged invoice is never
     * invisible just because no `Payment` row exists yet for it.
     *
     * @return array<string, mixed>
     */
    public function toListArray(): array
    {
        $latestPayment = $this->payments->sortByDesc('created_at')->first();

        return [
            ...$this->toDashboardArray(),
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
            ],
            'productsLabel' => $this->lines->pluck('description')->implode(', ') ?: '—',
            'paymentMethodLabel' => match (true) {
                $latestPayment?->paymentMethod !== null => trim(($latestPayment->paymentMethod->brand ?? 'Card').' ···· '.($latestPayment->paymentMethod->last4 ?? '••••')),
                $this->collection_mode === CollectionMode::Manual => 'Invoice',
                default => '—',
            },
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
            'status' => InvoiceStatus::class,
            'billing_reason' => InvoiceBillingReason::class,
            'collection_mode' => CollectionMode::class,
            'billing_address' => 'array',
            'customer_snapshot' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_at' => 'datetime',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'custom_data' => 'array',
        ];
    }
}
