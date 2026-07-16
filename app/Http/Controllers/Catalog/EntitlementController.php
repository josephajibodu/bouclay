<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use App\Models\EntitlementGrant;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entitlements CRUD (IMPLEMENTATION_V2 §V2-5).
 *
 * `code` is the API contract — it's the string an integrator's application
 * gates on — so it is immutable once created. Renaming it would silently
 * revoke access in every deployed `hasEntitlement('…')` check, which is
 * exactly the kind of billing-coupled surprise this layer exists to prevent.
 * The display `name` stays editable.
 */
class EntitlementController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewEntitlements', $team);

        $entitlements = $team->entitlements()
            ->with('grants.grantor')
            ->orderBy('code')
            ->get()
            ->map(fn (Entitlement $entitlement) => [
                'id' => $entitlement->id,
                'publicId' => $entitlement->public_id,
                'code' => $entitlement->code,
                'name' => $entitlement->name,
                'description' => $entitlement->description,
                'grants' => $entitlement->grants
                    ->map(fn (EntitlementGrant $grant) => [
                        'id' => $grant->id,
                        'grantorType' => $grant->grantor_type,
                        'grantorId' => $grant->grantor_id,
                        'grantorName' => $grant->grantor->name,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('catalog/entitlements', [
            'entitlements' => $entitlements,
            'grantors' => $this->grantorOptions($request),
            'canManage' => $request->user()->toTeamPermissions($team)->canManageEntitlements,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageEntitlements', $team);

        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:255',
                // The key application code checks against: snake_case, stable,
                // and unique per team (schema.md §4).
                'regex:/^[a-z0-9]+(_[a-z0-9]+)*$/',
                Rule::unique('entitlements')->where('team_id', $team->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'code.regex' => 'The code must be lowercase snake_case, like hd_streaming.',
        ]);

        $team->entitlements()->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$data['name']} created."]);

        return back();
    }

    /**
     * Update the display fields. `code` is deliberately not accepted.
     */
    public function update(Request $request, Entitlement $entitlement): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($entitlement->team_id === $team->id, 404);

        Gate::authorize('manageEntitlements', $team);

        $entitlement->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$entitlement->name} updated."]);

        return back();
    }

    /**
     * Delete an entitlement and its grants.
     *
     * Unlike plans and prices, an entitlement carries no billing history —
     * nothing references it, so there's nothing to preserve and no reason to
     * archive instead. The access it granted stops immediately, which is the
     * intent.
     */
    public function destroy(Request $request, Entitlement $entitlement): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($entitlement->team_id === $team->id, 404);

        Gate::authorize('manageEntitlements', $team);

        $entitlement->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$entitlement->code} deleted. Anything gating on it loses access."]);

        return back();
    }

    /**
     * Replace the set of plans/products granting this entitlement — the
     * grants editor saves the whole set rather than diffing client-side.
     */
    public function grants(Request $request, Entitlement $entitlement): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($entitlement->team_id === $team->id, 404);

        Gate::authorize('manageEntitlements', $team);

        $data = $request->validate([
            'grants' => ['present', 'array'],
            'grants.*.grantorType' => ['required', Rule::in(['plan', 'product'])],
            'grants.*.grantorId' => ['required', 'integer'],
        ]);

        $grants = $this->validGrantorsFor($team->id, $data['grants']);

        DB::transaction(function () use ($entitlement, $grants, $team) {
            $entitlement->grants()->delete();

            foreach ($grants as [$type, $id]) {
                $entitlement->grants()->create([
                    'team_id' => $team->id,
                    'grantor_type' => $type,
                    'grantor_id' => $id,
                ]);
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Grants updated for {$entitlement->code}."]);

        return back();
    }

    /**
     * Keep only grantors this team actually owns — a grantor id is a raw
     * integer from the client, so an unscoped save would let one team grant
     * its entitlement off another team's plan.
     *
     * @param  list<array{grantorType: string, grantorId: int}>  $submitted
     * @return list<array{0: string, 1: int}>
     */
    private function validGrantorsFor(int $teamId, array $submitted): array
    {
        $planIds = Plan::query()->where('team_id', $teamId)->pluck('id')->all();
        $productIds = Product::query()->where('team_id', $teamId)->pluck('id')->all();

        $valid = [];

        foreach ($submitted as $grant) {
            $owned = $grant['grantorType'] === 'plan'
                ? in_array($grant['grantorId'], $planIds, true)
                : in_array($grant['grantorId'], $productIds, true);

            abort_unless($owned, 404);

            $valid[] = [$grant['grantorType'], $grant['grantorId']];
        }

        return array_values(array_unique($valid, SORT_REGULAR));
    }

    /**
     * The plans and products this team can grant from.
     *
     * @return array{plans: list<array{id: int, name: string}>, products: list<array{id: int, name: string}>}
     */
    private function grantorOptions(Request $request): array
    {
        $team = $request->user()->currentTeam;

        return [
            'plans' => array_values($team->plans()
                ->orderBy('name')
                ->get()
                ->map(fn (Plan $plan) => ['id' => $plan->id, 'name' => $plan->name])
                ->all()),
            'products' => array_values($team->products()
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product) => ['id' => $product->id, 'name' => $product->name])
                ->all()),
        ];
    }
}
