<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreateTrialOffer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SaveTrialOfferRequest;
use App\Models\Price;
use App\Models\Product;
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
     * Add a free trial to an existing price.
     *
     * `$current_team` isn't used directly — see the same note on
     * ApiKeyController::destroy for why it must stay in the signature.
     */
    public function store(SaveTrialOfferRequest $request, string $current_team, Product $product, Price $price): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $price->product_id === $product->id, 404);

        Gate::authorize('manageTrialOffers', $team);

        $this->createTrialOffer->handle($price, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Free trial added']);

        return back();
    }

    /**
     * Edit a trial's duration in place.
     *
     * No subscriber lock yet — see CATALOG_DESIGN.md §7.2, which documents
     * the future rule (locked once a trial has live redemptions) for
     * Phase 5 to pick up once subscription_item_trials exists.
     */
    public function update(SaveTrialOfferRequest $request, string $current_team, Product $product, TrialOffer $trial_offer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $trial_offer->product_id === $product->id, 404);

        Gate::authorize('manageTrialOffers', $team);

        $trial_offer->trialPrice->update([
            'billing_interval' => $request->validated('duration_unit'),
            'billing_frequency' => $request->validated('duration_amount'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trial updated']);

        return back();
    }

    /**
     * Remove a trial. New subscriptions to this price stop offering it;
     * existing trials in progress are unaffected once Phase 5 exists.
     */
    public function destroy(Request $request, string $current_team, Product $product, TrialOffer $trial_offer): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $trial_offer->product_id === $product->id, 404);

        Gate::authorize('manageTrialOffers', $team);

        $trialPrice = $trial_offer->trialPrice;
        $trial_offer->delete();
        $trialPrice->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Trial removed']);

        return back();
    }
}
