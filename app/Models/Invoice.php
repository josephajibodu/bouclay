<?php

namespace App\Models;

use App\Actions\Webhooks\EmitOutboundEvent;
use App\Concerns\HasPublicId;
use App\Enums\CollectionMode;
use App\Enums\InvoiceBillingReason;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OutboundEventType;
use App\Support\Api\ApiMoney;
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
 * (schema.md §7). Every charge attempt against an invoice is a separate
 * {@see Payment} row.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $customer_id
 * @property int $billed_to_customer_id
 * @property int|null $subscription_id
 * @property string|null $number
 * @property InvoiceType $type
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
 * @property-read Customer $billedToCustomer
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, Refund> $refunds
 */
#[Fillable([
    'team_id', 'customer_id', 'billed_to_customer_id', 'subscription_id',
    'number', 'type', 'status',
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
     * Get the customer who actually pays — always equal to `customer` in
     * MVP; a distinct relation from day one so parent/child billing is
     * addable later without migrating invoice history (schema.md §8).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function billedToCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'billed_to_customer_id');
    }

    /**
     * Get the subscription this invoice was generated for, if any — null for
     * a one-off invoice.
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
     * Get the refunds issued against this invoice's payments (denormalised
     * FK — no join through payments needed).
     *
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Mark this invoice paid in full by a successful payment.
     */
    public function markPaid(Payment $payment): void
    {
        $wasPaid = $this->status === InvoiceStatus::Paid;

        $this->forceFill([
            'status' => InvoiceStatus::Paid,
            'amount_paid' => $this->total,
            'amount_due' => 0,
            'paid_at' => $payment->processed_at ?? now(),
        ])->save();

        if (! $wasPaid) {
            $this->loadMissing(['customer', 'subscription', 'team']);

            app(EmitOutboundEvent::class)->handle(
                $this->team,
                OutboundEventType::InvoicePaid,
                ['object' => $this->toWebhookObject($payment)],
            );
        }
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
     * Void this invoice — it can no longer be paid or collected.
     */
    public function markVoid(): void
    {
        $this->forceFill([
            'status' => InvoiceStatus::Void,
            'voided_at' => now(),
            'amount_due' => 0,
        ])->save();
    }

    /**
     * Mark this invoice uncollectible — the debt is written off.
     */
    public function markUncollectible(): void
    {
        $this->forceFill([
            'status' => InvoiceStatus::Uncollectible,
            'amount_due' => 0,
        ])->save();
    }

    /**
     * Whether this invoice can still be voided or marked uncollectible.
     */
    public function canBeCanceled(): bool
    {
        return $this->status === InvoiceStatus::Open;
    }

    /**
     * The URL a customer can use to pay this invoice — a direct Nomba checkout
     * link when one exists, otherwise the Bouclay hosted invoice page.
     */
    public function paymentLink(): ?string
    {
        if (! $this->canBeCanceled()) {
            return null;
        }

        $checkoutLink = $this->custom_data['checkout_link'] ?? null;

        if (is_string($checkoutLink) && $checkoutLink !== '') {
            return $checkoutLink;
        }

        return route('hosted.invoices.show', $this->public_id);
    }

    /**
     * Serialise for integrator webhook payloads.
     *
     * @return array<string, mixed>
     */
    public function toWebhookObject(?Payment $payment = null): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'billingReason' => $this->billing_reason->value,
            'collectionMode' => $this->collection_mode->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discount_total,
            'taxTotal' => $this->tax_total,
            'total' => $this->total,
            'amountPaid' => $this->amount_paid,
            'amountDue' => $this->amount_due,
            'paidAt' => $this->paid_at?->toISOString(),
            'customer' => [
                'id' => $this->customer->public_id,
                'email' => $this->customer->email,
                'name' => $this->customer->name,
            ],
            'subscription' => $this->subscription !== null ? [
                'id' => $this->subscription->public_id,
                'status' => $this->subscription->status->value,
            ] : null,
            'payment' => $payment?->toWebhookObject(),
        ];
    }

    /**
     * Serialise for the public Billing API.
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        $this->loadMissing(['customer', 'subscription', 'lines']);

        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'billingReason' => $this->billing_reason->value,
            'collectionMode' => $this->collection_mode->value,
            'currency' => $this->currency,
            'subtotal' => ApiMoney::toMajorUnits($this->subtotal),
            'discountTotal' => ApiMoney::toMajorUnits($this->discount_total),
            'taxTotal' => ApiMoney::toMajorUnits($this->tax_total),
            'total' => ApiMoney::toMajorUnits($this->total),
            'amountPaid' => ApiMoney::toMajorUnits($this->amount_paid),
            'amountDue' => ApiMoney::toMajorUnits($this->amount_due),
            'paidAt' => $this->paid_at?->toISOString(),
            'customer' => [
                'id' => $this->customer->public_id,
                'email' => $this->customer->email,
                'name' => $this->customer->name,
            ],
            'subscription' => $this->subscription !== null ? [
                'id' => $this->subscription->public_id,
                'status' => $this->subscription->status->value,
            ] : null,
            'payment' => null,
            'dueAt' => $this->due_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'lines' => $this->lines->map(fn (InvoiceLine $line) => [
                'description' => $line->description,
                'kind' => $line->kind->value,
                'quantity' => $line->quantity,
                'unitAmount' => ApiMoney::toMajorUnits($line->unit_amount),
                'discountAmount' => ApiMoney::toMajorUnits($line->discount_amount),
                'total' => ApiMoney::toMajorUnits($line->total),
            ])->all(),
        ];
    }

    /**
     * Serialise a row for invoice hub contexts (customer, subscription).
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
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discount_total,
            'total' => $this->total,
            'amountDue' => $this->amount_due,
            'dueAt' => $this->due_at?->toISOString(),
            'paidAt' => $this->paid_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Serialise a row for the global Invoices list.
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
     * Serialisation for the customer-facing hosted invoice page.
     *
     * @return array<string, mixed>
     */
    public function toHostedArray(): array
    {
        $snapshot = $this->customer_snapshot ?? [];
        $billing = $this->billing_address;
        $settings = $this->team->settings;

        return [
            'publicId' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'collectionMode' => $this->collection_mode->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discount_total,
            'total' => $this->total,
            'amountDue' => $this->amount_due,
            'dueAt' => $this->due_at?->toISOString(),
            'paidAt' => $this->paid_at?->toISOString(),
            'customer' => [
                'name' => $snapshot['name'] ?? $this->customer->name,
                'email' => $snapshot['email'] ?? $this->customer->email,
            ],
            'billingAddress' => $billing,
            'business' => [
                'name' => $this->team->name,
                'line1' => $this->team->line1,
                'line2' => $this->team->line2,
                'city' => $this->team->city,
                'postalCode' => $this->team->postal_code,
                'country' => $this->team->country,
            ],
            'invoiceFooter' => $settings?->invoice_footer,
            'lines' => $this->lines->map(fn (InvoiceLine $line): array => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unitAmount' => $line->unit_amount,
                'discountAmount' => $line->discount_amount,
                'total' => $line->total,
            ])->all(),
            'canPay' => $this->canBeCanceled(),
            'payUrl' => route('hosted.invoices.pay', $this->public_id),
            'returnUrl' => $this->returnUrl(),
        ];
    }

    /**
     * The merchant's "return to site" link for the customer after payment —
     * the first billed product's `website_url`, in line order.
     */
    private function returnUrl(): ?string
    {
        return $this->lines
            ->map(fn (InvoiceLine $line): ?string => $line->product?->website_url)
            ->filter()
            ->first();
    }

    /**
     * Full serialisation for the dedicated invoice detail page — lines,
     * snapshots, totals, and charge attempts. Keeps {@see toDashboardArray()}
     * and {@see toListArray()} lean for summary rows elsewhere.
     *
     * @return array<string, mixed>
     */
    public function toShowArray(): array
    {
        $snapshot = $this->customer_snapshot ?? [];
        $billing = $this->billing_address;
        $latestPayment = $this->payments->sortByDesc('created_at')->first();
        $settings = $this->team->settings;

        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'billingReason' => $this->billing_reason->value,
            'billingReasonLabel' => $this->billing_reason->label(),
            'collectionMode' => $this->collection_mode->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discountTotal' => $this->discount_total,
            'taxTotal' => $this->tax_total,
            'total' => $this->total,
            'amountPaid' => $this->amount_paid,
            'amountDue' => $this->amount_due,
            'periodStart' => $this->period_start?->toISOString(),
            'periodEnd' => $this->period_end?->toISOString(),
            'dueAt' => $this->due_at?->toISOString(),
            'finalizedAt' => $this->finalized_at?->toISOString(),
            'paidAt' => $this->paid_at?->toISOString(),
            'voidedAt' => $this->voided_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'customer' => [
                'id' => $this->customer_id,
                'name' => $snapshot['name'] ?? $this->customer->name,
                'email' => $snapshot['email'] ?? $this->customer->email,
            ],
            'billingAddress' => $billing,
            'subscription' => $this->subscription !== null ? [
                'id' => $this->subscription->id,
                'publicId' => $this->subscription->public_id,
            ] : null,
            'business' => [
                'name' => $this->team->name,
                'line1' => $this->team->line1,
                'line2' => $this->team->line2,
                'city' => $this->team->city,
                'postalCode' => $this->team->postal_code,
                'country' => $this->team->country,
            ],
            'invoiceFooter' => $settings?->invoice_footer,
            'paymentMethodLabel' => match (true) {
                $latestPayment?->paymentMethod !== null => trim(($latestPayment->paymentMethod->brand ?? 'Card').' ···· '.($latestPayment->paymentMethod->last4 ?? '••••')),
                $this->collection_mode === CollectionMode::Manual => 'Invoice',
                default => '—',
            },
            'lines' => $this->lines->map(fn (InvoiceLine $line): array => [
                'id' => $line->id,
                'kind' => $line->kind->value,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unitAmount' => $line->unit_amount,
                'subtotal' => $line->subtotal,
                'discountAmount' => $line->discount_amount,
                'taxAmount' => $line->tax_amount,
                'total' => $line->total,
                'periodStart' => $line->period_start?->toISOString(),
                'periodEnd' => $line->period_end?->toISOString(),
                'productName' => $line->product?->name,
                'priceLabel' => $line->price?->toPickerLabel(),
            ])->all(),
            'payments' => $this->payments
                ->sortByDesc('created_at')
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'publicId' => $payment->public_id,
                    'status' => $payment->status->value,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'paymentMethodLabel' => $payment->paymentMethod !== null
                        ? trim(($payment->paymentMethod->brand ?? 'Card').' ···· '.($payment->paymentMethod->last4 ?? '••••'))
                        : 'Invoice',
                    'processedAt' => $payment->processed_at?->toISOString(),
                    'failureReason' => $payment->failure_reason,
                ])->values()->all(),
            'canVoid' => $this->canBeCanceled(),
            'canMarkUncollectible' => $this->canBeCanceled(),
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
            'type' => InvoiceType::class,
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
