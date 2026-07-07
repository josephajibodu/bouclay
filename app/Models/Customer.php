<?php

namespace App\Models;

use App\Concerns\HasPortalToken;
use App\Concerns\HasPublicId;
use App\Enums\PaymentStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $portal_token
 * @property int $team_id
 * @property string|null $external_ref
 * @property string|null $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $currency
 * @property string|null $locale
 * @property string|null $country
 * @property int|null $default_payment_method_id
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read Collection<int, Address> $addresses
 * @property-read Collection<int, PaymentMethod> $paymentMethods
 * @property-read PaymentMethod|null $defaultPaymentMethod
 */
#[Fillable([
    'team_id', 'external_ref', 'name', 'email', 'phone',
    'currency', 'locale', 'country', 'default_payment_method_id', 'custom_data',
    'portal_token',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasPortalToken, HasPublicId, SoftDeletes;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'ctm';
    }

    /**
     * Get the team this customer belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the customer's address book.
     *
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * Get the customer's tokenised payment methods.
     *
     * @return HasMany<PaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * Get the customer's default payment method, if one is set.
     *
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function defaultPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'default_payment_method_id');
    }

    /**
     * Get the customer's subscriptions.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the customer's applied trial instances — denormalised here to
     * enforce a trial offer's once-per-customer rule (schema.md §5).
     *
     * @return HasMany<SubscriptionItemTrial, $this>
     */
    public function subscriptionItemTrials(): HasMany
    {
        return $this->hasMany(SubscriptionItemTrial::class);
    }

    /**
     * Get the customer's invoices.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the customer's charge attempts ({@see Payment}).
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Total ever successfully collected from this customer, in minor units —
     * powers the "Total spend" overview fact (IMPLEMENTATION.md Phase 4
     * carried-forward note).
     */
    public function totalSpend(): int
    {
        return (int) $this->payments()->where('status', PaymentStatus::Succeeded)->sum('amount');
    }

    /**
     * The name shown in the UI — falls back to the email, which is the one
     * field that is always present.
     */
    public function displayName(): string
    {
        return $this->name ?: $this->email;
    }

    /**
     * Serialise for integrator webhook payloads.
     *
     * @return array<string, mixed>
     */
    public function toWebhookObject(): array
    {
        return [
            'publicId' => $this->public_id,
            'email' => $this->email,
            'name' => $this->name,
            'currency' => $this->currency,
            'externalRef' => $this->external_ref,
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
        $this->loadMissing('paymentMethods');

        $defaultPaymentMethod = $this->default_payment_method_id !== null
            ? $this->paymentMethods->firstWhere('id', $this->default_payment_method_id)
            : null;

        return [
            'id' => $this->public_id,
            'email' => $this->email,
            'name' => $this->name,
            'currency' => $this->currency,
            'externalRef' => $this->external_ref,
            'createdAt' => $this->created_at?->toISOString(),
            'phone' => $this->phone,
            'status' => $this->trashed() ? 'archived' : 'active',
            'customData' => $this->custom_data,
            'defaultPaymentMethod' => $defaultPaymentMethod !== null
                ? ['id' => $defaultPaymentMethod->public_id]
                : null,
            'archivedAt' => $this->deleted_at?->toISOString(),
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
            'custom_data' => 'array',
        ];
    }
}
