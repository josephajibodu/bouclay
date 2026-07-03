<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreatePrice;
use App\Actions\Catalog\CreateTrialOffer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StorePriceRequest;
use App\Http\Requests\Catalog\UpdatePriceRequest;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PriceController extends Controller
{
    public function __construct(
        private readonly CreatePrice $createPrice,
        private readonly CreateTrialOffer $createTrialOffer,
    ) {
        //
    }

    /**
     * Add a price to an existing product.
     *
     * `$current_team` isn't used directly — see the same note on
     * ApiKeyController::destroy for why it must stay in the signature.
     */
    public function store(StorePriceRequest $request, string $current_team, Product $product): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id, 404);

        Gate::authorize('managePrices', $team);

        $data = $request->validated();
        $data['currency'] ??= $team->default_currency;

        $price = $this->createPrice->handle($product, $data);

        if ($trialData = $request->validated('trial')) {
            $this->createTrialOffer->handle($price, $trialData);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price added to '.$product->name]);

        return back();
    }

    /**
     * Update a price. Amount/currency/interval are freely editable in
     * Phase 3 — the usage lock (Price::hasBeenUsed()) only activates once
     * subscriptions exist (see CATALOG_DESIGN.md Principle 6).
     */
    public function update(UpdatePriceRequest $request, string $current_team, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $changingFinancialFields = collect(['unit_amount', 'currency', 'billing_interval'])
            ->some(fn (string $field) => $request->has($field));

        if ($changingFinancialFields && $price->hasBeenUsed()) {
            throw ValidationException::withMessages([
                'unit_amount' => 'This price has active subscribers — create a new price instead of editing this one.',
            ]);
        }

        $data = $request->validated();

        if (isset($data['unit_amount'])) {
            $data['unit_amount'] = (int) round($data['unit_amount'] * 100);
        }

        $price->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price updated']);

        return back();
    }

    /**
     * Archive a price — hides it from checkout without deleting history.
     */
    public function archive(Request $request, string $current_team, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $price->update(['status' => 'archived']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Price archived']);

        return back();
    }
}
