<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\RefundStatus;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One (possibly partial) reversal of a payment (schema.md §8).
 * `payments.status = refunded` marks the terminal state on the original
 * charge row only when fully reversed; the refund event itself lives here —
 * amount, reason, gateway reference — rather than overwriting the only copy
 * of what happened.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $payment_id
 * @property int $invoice_id
 * @property int $amount
 * @property string $currency
 * @property string|null $reason
 * @property RefundStatus $status
 * @property string|null $processor_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Payment $payment
 * @property-read Invoice $invoice
 */
#[Fillable([
    'team_id', 'payment_id', 'invoice_id', 'amount', 'currency',
    'reason', 'status', 'processor_reference',
])]
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 're';
    }

    /**
     * Get the team this refund belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the original charge being reversed.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the invoice the reversed payment settled.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => RefundStatus::class,
        ];
    }
}
