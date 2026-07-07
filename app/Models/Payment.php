<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\PaymentProcessor;
use App\Enums\PaymentStatus;
use App\Support\Api\ApiMoney;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One charge attempt against the processor (schema.md §7) — Bouclay records
 * every attempt, not just successes, since it runs its own dunning.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $invoice_id
 * @property int $customer_id
 * @property int|null $payment_method_id
 * @property PaymentProcessor $processor
 * @property string|null $processor_reference
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $risk_level
 * @property string|null $failure_code
 * @property string|null $failure_reason
 * @property int $attempt_number
 * @property string $idempotency_key
 * @property array<string, mixed>|null $raw_response
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Invoice $invoice
 * @property-read Customer $customer
 * @property-read PaymentMethod|null $paymentMethod
 */
#[Fillable([
    'team_id', 'invoice_id', 'customer_id', 'payment_method_id', 'processor',
    'processor_reference', 'amount', 'currency', 'status', 'risk_level',
    'failure_code', 'failure_reason', 'attempt_number', 'idempotency_key',
    'raw_response', 'processed_at',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'pay';
    }

    /**
     * Get the team this payment belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the invoice this payment settles.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the customer this payment was charged to.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the payment method charged, if any (null for a manual/offline
     * settlement).
     *
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Serialise for integrator webhook payloads.
     *
     * @return array<string, mixed>
     */
    public function toWebhookObject(): array
    {
        $this->loadMissing(['invoice', 'customer']);

        return [
            'publicId' => $this->public_id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'failureCode' => $this->failure_code,
            'failureReason' => $this->failure_reason,
            'attemptNumber' => $this->attempt_number,
            'processedAt' => $this->processed_at?->toISOString(),
            'invoice' => [
                'publicId' => $this->invoice->public_id,
            ],
            'customer' => [
                'publicId' => $this->customer->public_id,
            ],
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
            ...$this->toWebhookObject(),
            'amount' => ApiMoney::toMajorUnits($this->amount),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Serialise a charge attempt for nested hub lists (subscription, customer).
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'productsLabel' => $this->invoice->lines->pluck('description')->implode(', ') ?: '—',
            'customer' => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
            ],
            'paymentMethodLabel' => $this->paymentMethod !== null
                ? trim(($this->paymentMethod->brand ?? 'Card').' ···· '.($this->paymentMethod->last4 ?? '••••'))
                : 'Invoice',
            'processedAt' => $this->processed_at?->toISOString(),
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
            'processor' => PaymentProcessor::class,
            'status' => PaymentStatus::class,
            'raw_response' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
