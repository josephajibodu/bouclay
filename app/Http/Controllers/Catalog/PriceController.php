<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreatePrice;
use App\Actions\Catalog\ReplacePrice;
use App\Actions\Catalog\SyncPricePhases;
use App\Enums\CatalogStatus;
use App\Enums\PriceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StorePriceRequest;
use App\Http\Requests\Catalog\UpdatePriceRequest;
use App\Models\PaymentLink;
use App\Models\Price;
use App\Models\PriceTier;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class PriceController extends Controller
{
    public function __construct(
        private readonly CreatePrice $createPrice,
        private readonly ReplacePrice $replacePrice,
        private readonly SyncPricePhases $syncPricePhases,
    ) {
        //
    }

    /**
     * Add a price to an existing product, as a variant of one of the
     * product's plans when recurring.
     */
    public function store(StorePriceRequest $request, Product $product): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id, 404);

        Gate::authorize('managePrices', $team);

        $data = $request->validated();
        $data['currency'] ??= $team->default_currency;

        if (isset($data['plan_id'])) {
            $this->assertPlanBelongsToProduct($product, (int) $data['plan_id']);
        }

        $this->createPrice->handle($product, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price added to '.$product->name]);

        return back();
    }

    /**
     * The financial shape of a price — the fields whose edit, once the
     * price is referenced, must go through ReplacePrice instead of an
     * in-place UPDATE (schema.md §3). Cosmetic fields (name, custom_data)
     * and status stay editable in place regardless.
     */
    private const FINANCIAL_FIELDS = [
        'plan_id', 'unit_amount', 'currency', 'billing_interval',
        'billing_frequency', 'type', 'pricing_model', 'tiers',
        'trial_length', 'trial_unit', 'trial_requires_payment_info',
        'trial_once_per_customer',
    ];

    /**
     * Update a price. Reads as "edit" everywhere in the UI; what executes
     * depends on usage: an unreferenced price is edited in place, a
     * referenced one gets a successor row (`replaces_price_id`, version+1)
     * while the original is archived and its subscribers grandfathered.
     */
    public function update(UpdatePriceRequest $request, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $data = $request->validated();

        if (array_key_exists('plan_id', $data) && $data['plan_id'] !== null) {
            $this->assertPlanBelongsToProduct($product, (int) $data['plan_id']);
        }

        $changingFinancialFields = collect(self::FINANCIAL_FIELDS)
            ->some(fn (string $field) => $request->has($field));

        if ($changingFinancialFields && $price->hasBeenUsed()) {
            $successor = $this->replacePrice->handle($price, $data);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => "New price version v{$successor->version} created — existing subscribers keep the old price.",
            ]);

            return back();
        }

        $tiers = $data['tiers'] ?? null;
        unset($data['tiers']);

        if (array_key_exists('unit_amount', $data) && $data['unit_amount'] !== null) {
            $data['unit_amount'] = (int) round($data['unit_amount'] * 100);
        }

        if (array_key_exists('trial_length', $data) && $data['trial_length'] === null) {
            $data['trial_unit'] = null;
        }

        DB::transaction(function () use ($price, $data, $tiers) {
            $price->update($data);

            if ($tiers !== null) {
                $price->tiers()->delete();

                foreach (array_values($tiers) as $index => $tier) {
                    PriceTier::create([
                        'price_id' => $price->id,
                        'tier_index' => $index,
                        'up_to' => $tier['up_to'] ?? null,
                        'unit_amount' => (int) round($tier['unit_amount'] * 100),
                        'flat_amount' => isset($tier['flat_amount']) ? (int) round($tier['flat_amount'] * 100) : null,
                    ]);
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price updated']);

        return back();
    }

    /**
     * Replace a price's phase schedule — trials that transition, paid
     * intro periods, ramps. Only editable while the price is unreferenced;
     * after that, edit the price (which creates a successor) and author
     * phases there.
     */
    public function phases(Request $request, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $data = $request->validate([
            'phases' => ['present', 'array'],
            'phases.*.charge_price_id' => ['nullable', 'integer'],
            'phases.*.charge_price' => ['nullable', 'array'],
            'phases.*.charge_price.unit_amount' => ['required_with:phases.*.charge_price', 'numeric', 'min:0'],
            'phases.*.charge_price.name' => ['nullable', 'string', 'max:255'],
            'phases.*.duration_interval' => ['required', 'in:day,week,month,year'],
            'phases.*.duration_count' => ['required', 'integer', 'min:1'],
        ]);

        if ($price->hasBeenUsed()) {
            throw ValidationException::withMessages([
                'phases' => 'This price has subscribers — edit the price to create a new version, then author phases there.',
            ]);
        }

        try {
            $this->syncPricePhases->handle($price, $data['phases']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phases' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Phase schedule saved']);

        return back();
    }

    /**
     * Archive a price — hides it from checkout without deleting history.
     */
    public function archive(Request $request, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $price->update(['status' => 'archived']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price archived']);

        return back();
    }

    /**
     * Create or retrieve the shareable hosted checkout URL for this exact
     * catalog price. Recurring prices must be purchasable for new
     * subscriptions (active price, active plan, not phase-only); one-time
     * prices just need to be active with a fixed amount.
     */
    public function paymentLink(Request $request, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $sellable = $product->status !== CatalogStatus::Archived
            && ($price->unit_amount ?? 0) > 0
            && ($price->type === PriceType::Recurring
                ? $price->isPurchasableForNewSubscriptions()
                : $price->status === CatalogStatus::Active && $price->purchasable);

        if (! $sellable) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Payment links are available for active, fixed-amount prices under an active plan.',
            ]);

            return back();
        }

        $paymentLink = PaymentLink::query()->firstOrCreate(
            [
                'team_id' => $team->id,
                'price_id' => $price->id,
            ],
            [
                'product_id' => $product->id,
                'active' => true,
            ],
        );

        Inertia::flash('paymentLink', [
            'url' => $paymentLink->url(),
            'productName' => $product->name,
            'priceLabel' => $price->toPickerLabel(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment link ready']);

        return back();
    }

    /**
     * A price may only attach to a plan of its own product.
     */
    private function assertPlanBelongsToProduct(Product $product, int $planId): void
    {
        if (! $product->plans()->whereKey($planId)->exists()) {
            throw ValidationException::withMessages([
                'plan_id' => 'Choose a plan that belongs to this product.',
            ]);
        }
    }
}
