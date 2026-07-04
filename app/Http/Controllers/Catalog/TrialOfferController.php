<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreateTrialOffer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveTrialOfferRequest;
use App\Models\Price;
use App\Models\Product;
use App\Models\Team;
use App\Models\TrialOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class TrialOfferController extends Controller
{
    public function __construct(private readonly CreateTrialOffer $createTrialOffer)
    {
        //
    }

    /**
     * Create a trial offer on this product.
     */
    public function store(SaveTrialOfferRequest $request, Product $product): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id, 404);

        Gate::authorize('manageTrialOffers', $team);

        $this->assertPricesBelongToTeam($request, $team, $product);

        $this->createTrialOffer->handle($product, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trial created']);

        return back();
    }

    /**
     * Edit a trial in place — the trial price, transition target, and
     * repeat count are all real catalog data now, not a hidden price.
     *
     * No subscriber lock yet — see CATALOG_DESIGN.md §7.2, which documents
     * the future rule (locked once a trial has live redemptions) for
     * Phase 5 to pick up once subscription_item_trials exists.
     */
    public function update(SaveTrialOfferRequest $request, Product $product, TrialOffer $trial_offer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $trial_offer->product_id === $product->id, 404);

        Gate::authorize('manageTrialOffers', $team);

        $this->assertPricesBelongToTeam($request, $team, $product);

        $data = $request->validated();

        $trial_offer->update([
            'name' => $data['name'],
            'trial_price_id' => $data['trial_price_id'],
            'transition_to_different_product' => $data['transition_to_different_product'],
            'transition_product_id' => $data['transition_to_different_product']
                ? $data['transition_product_id']
                : $product->id,
            'transition_price_id' => $data['transition_price_id'],
            'duration_iterations' => $data['duration_iterations'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trial updated']);

        return back();
    }

    /**
     * Remove a trial offer. The trial and transition prices are real
     * catalog prices — removing the trial never deletes them.
     */
    public function destroy(Request $request, Product $product, TrialOffer $trial_offer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $trial_offer->product_id === $product->id, 404);

        Gate::authorize('manageTrialOffers', $team);

        $trial_offer->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trial removed']);

        return back();
    }

    /**
     * Guard against a request referencing another team's prices/products —
     * `exists:prices,id` only checks the row exists, not that it's ours.
     */
    private function assertPricesBelongToTeam(SaveTrialOfferRequest $request, Team $team, Product $product): void
    {
        $trialPrice = Price::findOrFail($request->validated('trial_price_id'));
        abort_unless($trialPrice->team_id === $team->id, 404);

        $transitionPrice = Price::findOrFail($request->validated('transition_price_id'));
        abort_unless($transitionPrice->team_id === $team->id, 404);

        if ($request->validated('transition_to_different_product')) {
            abort_unless($transitionPrice->product_id === $request->validated('transition_product_id'), 404);
        } else {
            abort_unless($transitionPrice->product_id === $product->id, 404);
        }
    }
}
