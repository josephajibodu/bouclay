<?php

namespace App\Models;

use App\Actions\Entitlements\ResolveCustomerEntitlements;
use App\Actions\Webhooks\EmitOutboundEvent;
use App\Concerns\HasPortalToken;
use App\Concerns\HasPublicId;
use App\Enums\OutboundEventType;
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
use Illuminate\Support\Collection as SupportCollection;

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
 * @property int|null $parent_customer_id
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
     * Announce customer changes to integrators (schema.md §9).
     *
     * A hook rather than an emission inside CreateCustomer, because that
     * action is not the only way a customer comes into existence — a payment
     * link creates one inline for a first-time buyer, and that customer is
     * every bit as real to an integrator. Archiving is a soft delete, and
     * restoring is neither a create nor a plain update, so all three land on
     * `customer.updated` with the status on the object.
     */
    protected static function booted(): void
    {
        static::created(fn (Customer $customer) => $customer->emit(OutboundEventType::CustomerCreated));
        static::updated(fn (Customer $customer) => $customer->emit(OutboundEventType::CustomerUpdated));
        static::deleted(fn (Customer $customer) => $customer->emit(OutboundEventType::CustomerUpdated));
        static::restored(fn (Customer $customer) => $customer->emit(OutboundEventType::CustomerUpdated));
    }

    private function emit(OutboundEventType $type): void
    {
        $this->loadMissing('team');

        app(EmitOutboundEvent::class)->handle(
            $this->team,
            $type,
            ['object' => $this->toWebhookObject()],
        );
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
     * What this customer can access right now — the union of entitlements
     * granted by the plans/products on their access-granting subscriptions
     * (IMPLEMENTATION_V2 §V2-5).
     *
     * Deliberately not a relation: access is a *computed* answer that depends
     * on subscription status and the `ends_at` grace window, not a join an
     * integrator could get subtly wrong.
     *
     * @return SupportCollection<string, Entitlement>
     */
    public function entitlements(): SupportCollection
    {
        return app(ResolveCustomerEntitlements::class)->handle($this);
    }

    /**
     * The entitlement codes this customer holds — what application code
     * gates on.
     *
     * @return list<string>
     */
    public function entitlementCodes(): array
    {
        return app(ResolveCustomerEntitlements::class)->codes($this);
    }

    /**
     * Whether this customer can access a named capability.
     */
    public function hasEntitlement(string $code): bool
    {
        return $this->entitlements()->has($code);
    }

    /**
     * Get the customer's trial redemptions — the durable rows enforcing
     * `trial_once_per_customer` (schema.md §3).
     *
     * @return HasMany<PriceTrialRedemption, $this>
     */
    public function priceTrialRedemptions(): HasMany
    {
        return $this->hasMany(PriceTrialRedemption::class);
    }

    /**
     * Get the customer's discount redemptions.
     *
     * @return HasMany<DiscountRedemption, $this>
     */
    public function discountRedemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    /**
     * Get the child accounts billed through this customer — reserved for
     * future parent/child billing (schema.md §2), unused in MVP logic.
     *
     * @return HasMany<Customer, $this>
     */
    public function childCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'parent_customer_id');
    }

    /**
     * Get the parent account, when this customer is a child (schema.md §2).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function parentCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'parent_customer_id');
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
            // Archiving is a soft delete, so status is derived rather than
            // stored — consumers still read it off every payload (§V2-6).
            'status' => $this->trashed() ? 'archived' : 'active',
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
            'portalUrl' => $this->portalUrl(),
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
