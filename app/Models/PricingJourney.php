<?php

namespace App\Models;

use App\Enums\CatalogStatus;
use Database\Factories\PricingJourneyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * "Pricing Journey" in the UI, `price_phases` in the schema (schema.md §3) —
 * a reusable, merchant-authored multi-phase commercial offer scoped to one
 * Product, e.g. "$1/mo for 3 months, then $10/mo forever." Its steps
 * ({@see PricingJourneyStep}) reference real `prices` rows across any of
 * the product's plans — a journey is deliberately Plan-agnostic, since its
 * whole point is often to move a customer between plans.
 *
 * A journey is a template only: it never holds billing state. The moment a
 * subscription is created through it, its steps are copied into a
 * customer-owned {@see SubscriptionSchedule} — from that point on, editing
 * this journey never touches that schedule, and editing that schedule never
 * touches this journey or any other customer.
 *
 * @property int $id
 * @property int $team_id
 * @property int $product_id
 * @property string $name
 * @property string|null $description
 * @property CatalogStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Product $product
 * @property-read Collection<int, PricingJourneyStep> $steps
 */
#[Fillable(['team_id', 'product_id', 'name', 'description', 'status'])]
class PricingJourney extends Model
{
    /** @use HasFactory<PricingJourneyFactory> */
    use HasFactory;

    protected $table = 'price_phases';

    /**
     * Get the team this journey belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the product this journey is scoped to — every step's price must
     * belong to this same product (enforced by {@see \App\Actions\Catalog\SyncPricingJourney}).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get this journey's ordered steps.
     *
     * @return HasMany<PricingJourneyStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(PricingJourneyStep::class, 'price_phases_id')->orderBy('sequence');
    }

    /**
     * Get the schedules ever copied from this journey — informational only,
     * for reporting ("how many active subs came from Starter Offer"). Never
     * read by billing/invoicing/entitlement logic.
     *
     * @return HasMany<SubscriptionSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(SubscriptionSchedule::class, 'price_phases_id');
    }

    /**
     * Whether this journey has ever been copied into a live schedule — the
     * trigger for archive-not-delete (never a hard block on editing: a
     * journey stays freely editable for life, since editing it is defined
     * to never touch schedules already copied from it).
     */
    public function hasBeenUsed(): bool
    {
        return $this->schedules()->exists();
    }

    /**
     * A one-line, auto-generated summary of this journey's steps — e.g.
     * "$1/mo for 3 months, then $10/mo" — so admin UI and customer-facing
     * pricing pages pull from one source instead of merchants hand-writing
     * copy that can drift from actual billing. Requires `steps.price`
     * loaded.
     */
    public function describe(): string
    {
        $steps = $this->steps;

        if ($steps->isEmpty()) {
            return 'No steps configured';
        }

        return $steps
            ->map(function (PricingJourneyStep $step): string {
                $label = $step->price->toPickerLabel();

                if ($step->duration_interval === null || $step->duration_count === null) {
                    return "{$label} forever";
                }

                $unit = $step->duration_interval->label($step->duration_count);

                return "{$label} for {$step->duration_count} {$unit}";
            })
            ->implode(', then ');
    }

    /**
     * Format this journey for the frontend catalog page. Requires
     * `steps.price` loaded.
     *
     * @return array<string, mixed>
     */
    public function toCatalogArray(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'autoDescription' => $this->relationLoaded('steps') ? $this->describe() : null,
            'steps' => $this->relationLoaded('steps')
                ? $this->steps->map(fn (PricingJourneyStep $step) => [
                    'id' => $step->id,
                    'sequence' => $step->sequence,
                    'priceId' => $step->price_id,
                    'priceLabel' => $step->price->toPickerLabel(),
                    'priceUnitAmount' => $step->price->unit_amount !== null ? $step->price->unit_amount / 100 : null,
                    'currency' => $step->price->currency,
                    'quantity' => $step->quantity,
                    'durationInterval' => $step->duration_interval?->value,
                    'durationCount' => $step->duration_count,
                    'isTerminal' => $step->isTerminal(),
                ])->all()
                : [],
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
            'status' => CatalogStatus::class,
        ];
    }
}
