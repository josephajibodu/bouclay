<?php

namespace App\Models;

use App\Actions\Catalog\ReplacePrice;
use App\Concerns\HasPublicId;
use App\Enums\BillingInterval;
use App\Enums\CatalogStatus;
use App\Enums\PlanStatus;
use App\Enums\PriceType;
use App\Enums\PricingModel;
use App\Enums\TaxMode;
use App\Enums\TrialUnit;
use App\Exceptions\ImmutablePriceViolation;
use App\Support\Api\ApiMoney;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A billable variant of a plan: interval × currency × amount, plus its own
 * trial config (schema.md §3). A price with `plan_id = null` is a one-time
 * price sold directly off the product and can never be referenced by a
 * subscription_item.
 *
 * Immutability invariant: once referenced by any subscription_item or
 * invoice_line the row is append-only — a merchant "edit" creates a new row
 * (`replaces_price_id` → this one, version+1) and archives this one. Only
 * `status` and `custom_data` are ever safe to mutate on a live price.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property int $product_id
 * @property int|null $plan_id
 * @property string|null $name
 * @property PriceType $type
 * @property PricingModel $pricing_model
 * @property int|null $unit_amount
 * @property string $currency
 * @property BillingInterval|null $billing_interval
 * @property int $billing_frequency
 * @property int|null $package_size
 * @property TaxMode $tax_mode
 * @property CatalogStatus $status
 * @property int|null $replaces_price_id
 * @property int $version
 * @property int|null $trial_length
 * @property TrialUnit|null $trial_unit
 * @property bool $trial_requires_payment_info
 * @property bool $trial_once_per_customer
 * @property bool $purchasable
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Product $product
 * @property-read Plan|null $plan
 * @property-read Price|null $replacesPrice
 * @property-read Collection<int, PriceTier> $tiers
 * @property-read Collection<int, PriceTrialRedemption> $trialRedemptions
 * @property-read PaymentLink|null $paymentLink
 */
#[Fillable([
    'team_id', 'product_id', 'plan_id', 'name', 'type', 'pricing_model',
    'unit_amount', 'currency', 'billing_interval', 'billing_frequency',
    'package_size', 'tax_mode', 'status', 'replaces_price_id', 'version',
    'trial_length', 'trial_unit', 'trial_requires_payment_info',
    'trial_once_per_customer', 'purchasable', 'custom_data',
])]
class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use HasFactory, HasPublicId;

    /**
     * The price-defining columns frozen once the row is referenced by a
     * subscription item or invoice line (schema.md §3). Everything else —
     * `name` (snapshots protect history), `status` (archiving), `purchasable`
     * (catalog visibility), `custom_data` — stays mutable for life.
     *
     * @var list<string>
     */
    public const FROZEN_COLUMNS = [
        'team_id', 'product_id', 'plan_id', 'type', 'pricing_model',
        'unit_amount', 'currency', 'billing_interval', 'billing_frequency',
        'package_size', 'tax_mode', 'replaces_price_id', 'version',
        'trial_length', 'trial_unit', 'trial_requires_payment_info',
        'trial_once_per_customer',
    ];

    /**
     * The last line of defense for the immutability invariant: any code
     * path — controller, action, tinker — that mutates a frozen column on
     * a referenced price throws. The legal edit path is
     * {@see ReplacePrice}.
     */
    protected static function booted(): void
    {
        static::saving(function (Price $price): void {
            if (! $price->exists) {
                return;
            }

            $frozenDirty = array_values(array_intersect(
                array_keys($price->getDirty()),
                self::FROZEN_COLUMNS,
            ));

            if ($frozenDirty !== [] && $price->hasBeenUsed()) {
                throw ImmutablePriceViolation::forColumns($price, $frozenDirty);
            }
        });
    }

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'price';
    }

    /**
     * Scope to the prices a NEW subscription may reference — the single
     * definition of purchasability every picker, payment link, and API
     * validator reads (IMPLEMENTATION_V2 §V2-1): an active, purchasable,
     * plan-bearing recurring price whose plan is itself active (the
     * draft/archived-plan rule, schema.md §3).
     *
     * @param  Builder<Price>  $query
     * @return Builder<Price>
     */
    public function scopePurchasableForNewSubscriptions(Builder $query): Builder
    {
        return $query
            ->where('status', CatalogStatus::Active)
            ->where('purchasable', true)
            ->where('type', PriceType::Recurring)
            ->whereNotNull('plan_id')
            ->whereHas('plan', fn (Builder $plan) => $plan->where('status', PlanStatus::Active));
    }

    /**
     * Whether a new subscription may reference this specific row — the
     * row-level twin of {@see scopePurchasableForNewSubscriptions()}.
     */
    public function isPurchasableForNewSubscriptions(): bool
    {
        return static::query()
            ->whereKey($this->id)
            ->purchasableForNewSubscriptions()
            ->exists();
    }

    /**
     * Get the team this price belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the product this price belongs to (denormalised — always
     * resolvable whether or not plan_id is set).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the plan this price is a variant of, when it has one.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the price this row superseded, when it was created by an "edit".
     *
     * @return BelongsTo<Price, $this>
     */
    public function replacesPrice(): BelongsTo
    {
        return $this->belongsTo(Price::class, 'replaces_price_id');
    }

    /**
     * Get the tiers driving this price, when its pricing model uses them.
     *
     * @return HasMany<PriceTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('tier_index');
    }

    /**
     * Get the trial redemptions recorded against this price
     * (`trial_once_per_customer` anti-abuse).
     *
     * @return HasMany<PriceTrialRedemption, $this>
     */
    public function trialRedemptions(): HasMany
    {
        return $this->hasMany(PriceTrialRedemption::class);
    }

    /**
     * Get the subscription items referencing this price.
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    /**
     * Get the invoice lines referencing this price.
     *
     * @return HasMany<InvoiceLine, $this>
     */
    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Get the durable hosted checkout link for this price, when one exists.
     *
     * @return HasOne<PaymentLink, $this>
     */
    public function paymentLink(): HasOne
    {
        return $this->hasOne(PaymentLink::class);
    }

    /**
     * Whether this price carries a simple trial (schema.md §5). Free vs.
     * paid is inferred from the trial-phase price, not stored.
     */
    public function hasTrial(): bool
    {
        return $this->trial_length !== null;
    }

    /**
     * Whether this price starts a subscription on a **free** trial: always
     * free during its `trial_length` window (schema.md §5). A price is no
     * longer intrinsically "phased" — a paid intro/ramp offer now comes from
     * a Pricing Journey chosen at subscription-create time, resolved by
     * {@see \App\Actions\Subscriptions\CreateSubscription} from the
     * journey/schedule input rather than from the price itself.
     *
     * This is the one shared rule: every surface that decides "does day 0 bill
     * anything" — subscription create and payment-link checkout alike — asks
     * here, so the two can't drift apart.
     */
    public function startsFreeTrial(): bool
    {
        return $this->trial_length !== null;
    }

    /**
     * A picker-friendly summary of the simple trial this price starts, or
     * null when it has none (schema.md §5).
     *
     * @return array{label: string, free: bool}|null
     */
    public function trialSummary(): ?array
    {
        if ($this->trial_length === null || $this->trial_unit === null) {
            return null;
        }

        // Adjectival unit — "7-day free trial", never "7-days".
        return ['label' => "{$this->trial_length}-{$this->trial_unit->value} free trial", 'free' => true];
    }

    /**
     * Format this price for the frontend — amounts converted back to major
     * currency units (see App\Actions\Catalog\CreatePrice for the reverse).
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'planId' => $this->plan_id,
            'name' => $this->name,
            'type' => $this->type,
            'pricingModel' => $this->pricing_model,
            'unitAmount' => $this->unit_amount !== null ? $this->unit_amount / 100 : null,
            'currency' => $this->currency,
            'billingInterval' => $this->billing_interval,
            'billingFrequency' => $this->billing_frequency,
            'taxMode' => $this->tax_mode,
            'status' => $this->status,
            'version' => $this->version,
            'purchasable' => $this->purchasable,
            'trialLength' => $this->trial_length,
            'trialUnit' => $this->trial_unit,
            'trialRequiresPaymentInfo' => $this->trial_requires_payment_info,
            'trialOncePerCustomer' => $this->trial_once_per_customer,
            'hasBeenUsed' => $this->hasBeenUsed(),
            'customData' => $this->custom_data,
            'paymentLink' => $this->relationLoaded('paymentLink') && $this->paymentLink !== null
                ? [
                    'id' => $this->paymentLink->public_id,
                    'url' => $this->paymentLink->url(),
                    'priceLabel' => $this->toPickerLabel(),
                ]
                : null,
            'createdAt' => $this->created_at?->toISOString(),
            'tiers' => $this->relationLoaded('tiers')
                ? $this->tiers->map(fn (PriceTier $tier) => [
                    'id' => $tier->id,
                    'tierIndex' => $tier->tier_index,
                    'upTo' => $tier->up_to,
                    'unitAmount' => $tier->unit_amount / 100,
                    'flatAmount' => $tier->flat_amount !== null ? $tier->flat_amount / 100 : null,
                ])->all()
                : [],
        ];
    }

    /**
     * Serialise for the public Billing API (amounts in major units).
     *
     * @return array<string, mixed>
     */
    public function toApiObject(): array
    {
        $this->loadMissing(['product', 'plan', 'tiers']);

        return [
            'id' => $this->public_id,
            'productId' => $this->product->public_id,
            'planId' => $this->plan?->public_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'pricingModel' => $this->pricing_model->value,
            'unitAmount' => ApiMoney::toMajorUnits($this->unit_amount),
            'currency' => $this->currency,
            'billingInterval' => $this->billing_interval?->value,
            'billingFrequency' => $this->billing_frequency,
            'taxMode' => $this->tax_mode->value,
            'status' => $this->status->value,
            'version' => $this->version,
            'purchasable' => $this->purchasable,
            'trialLength' => $this->trial_length,
            'trialUnit' => $this->trial_unit?->value,
            'trialRequiresPaymentInfo' => $this->trial_requires_payment_info,
            'trialOncePerCustomer' => $this->trial_once_per_customer,
            'customData' => $this->custom_data,
            'createdAt' => $this->created_at?->toISOString(),
            'tiers' => $this->tiers->map(fn (PriceTier $tier) => [
                'tierIndex' => $tier->tier_index,
                'upTo' => $tier->up_to,
                'unitAmount' => ApiMoney::toMajorUnits($tier->unit_amount),
                'flatAmount' => ApiMoney::toMajorUnits($tier->flat_amount),
            ])->all(),
        ];
    }

    /**
     * Format this price as a short label for pickers, e.g. "NGN 15,000 /
     * every month" for a recurring price or "NGN 1,000 flat" for a
     * one-time one — falls back to a name only when one was set, since an
     * auto-generated amount reads more intuitively than a bare "Price".
     */
    public function toPickerLabel(): string
    {
        if ($this->name) {
            return $this->name;
        }

        if ($this->pricing_model === PricingModel::Graduated) {
            return 'Graduated pricing';
        }

        if ($this->unit_amount === null) {
            return 'Custom pricing';
        }

        $amount = number_format($this->unit_amount / 100, 2);

        if ($this->type === PriceType::OneTime || $this->billing_interval === null) {
            return "{$this->currency} {$amount} flat";
        }

        $frequency = $this->billing_frequency > 1 ? "{$this->billing_frequency} " : '';

        return "{$this->currency} {$amount} / every {$frequency}{$this->billing_interval->value}";
    }

    /**
     * Determine if the price is archived.
     */
    public function isArchived(): bool
    {
        return $this->status === CatalogStatus::Archived;
    }

    /**
     * Whether this price has ever been referenced by a subscription item or
     * invoice line — the trigger for the immutability invariant: financial
     * fields on a used price are frozen; an "edit" must create a successor
     * row instead (ReplacePrice, IMPLEMENTATION_V2 §V2-1).
     */
    public function hasBeenUsed(): bool
    {
        return $this->subscriptionItems()->exists() || $this->invoiceLines()->exists();
    }

    /**
     * Whether this price is step 0 of an active Pricing Journey — a payment
     * link can't express "this price is step 0 of Journey J" (Journeys are
     * Product-scoped, not Price-scoped), so selling this price directly via
     * a link would silently regress it to a flat price that never advances.
     * Deferred to v2 (schema.md §3) — guarded here rather than left silent.
     */
    public function startsPricingJourney(): bool
    {
        return PricingJourneyStep::query()
            ->where('price_id', $this->id)
            ->where('sequence', 0)
            ->whereHas('journey', fn (Builder $query) => $query->where('status', CatalogStatus::Active))
            ->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PriceType::class,
            'pricing_model' => PricingModel::class,
            'billing_interval' => BillingInterval::class,
            'tax_mode' => TaxMode::class,
            'status' => CatalogStatus::class,
            'version' => 'integer',
            'trial_length' => 'integer',
            'trial_unit' => TrialUnit::class,
            'trial_requires_payment_info' => 'boolean',
            'trial_once_per_customer' => 'boolean',
            'purchasable' => 'boolean',
            'custom_data' => 'array',
        ];
    }
}
