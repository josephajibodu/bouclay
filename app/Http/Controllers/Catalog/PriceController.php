<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreatePrice;
use App\Enums\CatalogStatus;
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

class PriceController extends Controller
{
    public function __construct(private readonly CreatePrice $createPrice)
    {
        //
    }

    /**
     * Add a price to an existing product.
     */
    public function store(StorePriceRequest $request, Product $product): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id, 404);

        Gate::authorize('managePrices', $team);

        $data = $request->validated();
        $data['currency'] ??= $team->default_currency;

        $this->createPrice->handle($product, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price added to '.$product->name]);

        return back();
    }

    /**
     * The "shape" of a price — locked in place once a subscription has ever
     * referenced it (see CATALOG_DESIGN.md Principle 6). Cosmetic fields
     * (name, custom_data) and status (archiving) stay editable regardless.
     */
    private const FINANCIAL_FIELDS = [
        'unit_amount', 'currency', 'billing_interval', 'billing_frequency',
        'type', 'pricing_model', 'tiers',
    ];

    /**
     * Update a price. Every field is freely editable in place until the
     * price has been referenced by a subscription — the usage lock
     * (Price::hasBeenUsed()) only activates once subscriptions exist
     * (Phase 5), at which point only name/custom_data may still change.
     */
    public function update(UpdatePriceRequest $request, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $changingFinancialFields = collect(self::FINANCIAL_FIELDS)
            ->some(fn (string $field) => $request->has($field));

        if ($changingFinancialFields && $price->hasBeenUsed()) {
            throw ValidationException::withMessages([
                'unit_amount' => 'This price has active subscribers — create a new price instead of editing this one.',
            ]);
        }

        $data = $request->validated();
        $tiers = $data['tiers'] ?? null;
        unset($data['tiers']);

        if (array_key_exists('unit_amount', $data) && $data['unit_amount'] !== null) {
            $data['unit_amount'] = (int) round($data['unit_amount'] * 100);
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
     * catalog price.
     */
    public function paymentLink(Request $request, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        if ($product->status === CatalogStatus::Archived || $price->status === CatalogStatus::Archived || ($price->unit_amount ?? 0) <= 0) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Payment links are available for active, fixed-amount prices.',
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
}
