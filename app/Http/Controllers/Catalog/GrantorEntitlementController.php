<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\EntitlementGrant;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The grants editor from the *grantor's* side (IMPLEMENTATION_V2 §V2-5):
 * "what does Premium include?" rather than "what grants hd_streaming?".
 *
 * Both directions edit the same `entitlement_grants` rows — this exists
 * because it's the question a merchant actually asks while looking at a plan,
 * and making them go find the entitlement to answer it is backwards.
 */
class GrantorEntitlementController extends Controller
{
    /**
     * Set the entitlements a product grants to everyone subscribed to it.
     */
    public function product(Request $request, Product $product): RedirectResponse
    {
        $team = $this->authorizeFor($request);

        abort_unless($product->team_id === $team->id, 404);

        return $this->sync($request, $team, $product, $product->name);
    }

    /**
     * Set the entitlements a plan grants.
     */
    public function plan(Request $request, Product $product, Plan $plan): RedirectResponse
    {
        $team = $this->authorizeFor($request);

        abort_unless($product->team_id === $team->id && $plan->product_id === $product->id, 404);

        return $this->sync($request, $team, $plan, $plan->name);
    }

    private function authorizeFor(Request $request): Team
    {
        $team = $request->user()->currentTeam;

        // Editing grants is editing entitlements, whichever page you're on.
        Gate::authorize('manageEntitlements', $team);

        return $team;
    }

    /**
     * Replace this grantor's grants with the submitted set.
     */
    private function sync(Request $request, Team $team, Model $grantor, string $label): RedirectResponse
    {
        $data = $request->validate([
            'entitlementIds' => ['present', 'array'],
            'entitlementIds.*' => ['integer'],
        ]);

        // Ids arrive as raw integers from the browser, so re-derive which are
        // really this team's rather than trusting them.
        $entitlementIds = Entitlement::query()
            ->where('team_id', $team->id)
            ->whereIn('id', $data['entitlementIds'])
            ->pluck('id');

        abort_unless($entitlementIds->count() === count(array_unique($data['entitlementIds'])), 404);

        DB::transaction(function () use ($team, $grantor, $entitlementIds) {
            EntitlementGrant::query()
                ->where('team_id', $team->id)
                ->where('grantor_type', $grantor->getMorphClass())
                ->where('grantor_id', $grantor->getKey())
                ->delete();

            foreach ($entitlementIds as $entitlementId) {
                EntitlementGrant::query()->create([
                    'team_id' => $team->id,
                    'entitlement_id' => $entitlementId,
                    // The enforced morph map turns this into the stable
                    // `plan`/`product` alias, never a class FQN.
                    'grantor_type' => $grantor->getMorphClass(),
                    'grantor_id' => $grantor->getKey(),
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Entitlements updated for {$label}."]);

        return back();
    }
}
