<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\PlanStatus;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Plans CRUD on the product detail page (drawer idiom) — the named tiers
 * ("Premium") whose billable variants live on `prices`. Archiving is a
 * status change, never a delete: subscription items reference plans.
 */
class PlanController extends Controller
{
    /**
     * Add a plan to a product.
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id, 404);

        Gate::authorize('managePlans', $team);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(PlanStatus::class)],
        ]);

        $plan = $product->plans()->create([
            'team_id' => $team->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? PlanStatus::Active,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$plan->name} plan added to {$product->name}"]);

        return back();
    }

    /**
     * Update a plan's identity or lifecycle. Prices under a `draft` or
     * `archived` plan stop being purchasable the moment this saves — the
     * rule lives in Price::purchasableForNewSubscriptions(), not here.
     */
    public function update(Request $request, Product $product, Plan $plan): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $plan->product_id === $product->id, 404);

        Gate::authorize('managePlans', $team);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(PlanStatus::class)],
        ]);

        $plan->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$plan->name} plan updated"]);

        return back();
    }
}
