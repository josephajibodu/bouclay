<?php

namespace App\Models;

use App\Actions\PaymentMethods\StoreTokenizedPaymentMethod;
use App\Actions\Webhooks\EmitOutboundEvent;
use App\Concerns\HasPublicId;
use App\Enums\OutboundEventType;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentProcessor;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $customer_id
 * @property PaymentProcessor $processor
 * @property string $processor_token
 * @property PaymentMethodType $type
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property string|null $fingerprint
 * @property string|null $issuer
 * @property int|null $billing_address_id
 * @property bool $is_default
 * @property PaymentMethodStatus $status
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Customer $customer
 * @property-read Address|null $billingAddress
 */
#[Fillable([
    'team_id', 'customer_id', 'processor', 'processor_token', 'type',
    'brand', 'last4', 'exp_month', 'exp_year', 'fingerprint', 'issuer',
    'billing_address_id', 'is_default', 'status', 'custom_data',
])]
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'pm';
    }

    /**
     * Announce payment-method changes to integrators (schema.md §9).
     *
     * `created` stays in {@see StoreTokenizedPaymentMethod},
     * which owns tokenisation. Removal is a soft delete, and a card going
     * away matters as much to an integrator as one arriving.
     */
    protected static function booted(): void
    {
        static::updated(fn (PaymentMethod $method) => $method->emitUpdated());
        static::deleted(fn (PaymentMethod $method) => $method->emitUpdated());
    }

    private function emitUpdated(): void
    {
        $this->loadMissing('team');

        app(EmitOutboundEvent::class)->handle(
            $this->team,
            OutboundEventType::PaymentMethodUpdated,
            ['object' => $this->toWebhookObject()],
        );
    }

    /**
     * Get the team this payment method belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the customer this payment method belongs to.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the billing address linked to this payment method, if any.
     *
     * @return BelongsTo<Address, $this>
     */
    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    /**
     * Determine if the card is past its expiry.
     */
    public function isExpired(): bool
    {
        if ($this->status === PaymentMethodStatus::Expired) {
            return true;
        }

        if ($this->exp_month === null || $this->exp_year === null) {
            return false;
        }

        return Carbon::createFromDate($this->exp_year, $this->exp_month, 1)
            ->endOfMonth()
            ->isPast();
    }

    /**
     * Serialise for the customer dashboard. The processor token is never
     * exposed — only its shape — since it's a chargeable credential.
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'processor' => $this->processor->value,
            'type' => $this->type->value,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'expMonth' => $this->exp_month,
            'expYear' => $this->exp_year,
            'issuer' => $this->issuer,
            'isDefault' => $this->is_default,
            'isExpired' => $this->isExpired(),
            'status' => $this->status->value,
            'billingAddressId' => $this->billing_address_id,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Serialise for integrator webhook payloads.
     *
     * @return array<string, mixed>
     */
    public function toWebhookObject(): array
    {
        $this->loadMissing('customer');

        return [
            'publicId' => $this->public_id,
            'processor' => $this->processor->value,
            'type' => $this->type->value,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'expMonth' => $this->exp_month,
            'expYear' => $this->exp_year,
            'isDefault' => $this->is_default,
            'status' => $this->status->value,
            'customer' => [
                'publicId' => $this->customer->public_id,
            ],
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Serialise for the public Billing API.
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        $this->loadMissing('customer');

        return [
            'id' => $this->public_id,
            'processor' => $this->processor->value,
            'type' => $this->type->value,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'expMonth' => $this->exp_month,
            'expYear' => $this->exp_year,
            'isDefault' => $this->is_default,
            'status' => $this->status->value,
            'customer' => [
                'id' => $this->customer->public_id,
            ],
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
            'type' => PaymentMethodType::class,
            'status' => PaymentMethodStatus::class,
            'is_default' => 'boolean',
            'custom_data' => 'array',
        ];
    }
}
