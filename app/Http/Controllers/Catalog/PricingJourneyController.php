<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\SyncPricingJourney;
use App\Http\Controllers\Controller;
use App\Models\PricingJourney;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

class PricingJourneyController extends Controller
{
    public function __construct(private readonly SyncPricingJourney $syncPricingJourney)
    {
        //
    }

    /**
     * Create a Pricing Journey for this product — name, description, and its
     * full ordered step list in one form (schema.md §3).
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id, 404);

        Gate::authorize('managePrices', $team);

        $data = $this->validateJourney($request);

        try {
            $this->syncPricingJourney->handle($team, $product, $data);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['steps' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pricing journey created']);

        return back();
    }

    /**
     * Update a journey's name/description and replace its step list —
     * freely editable for life, since editing it never touches a schedule
     * already copied from it (the whole point of the copy-on-create model).
     */
    public function update(Request $request, Product $product, PricingJourney $journey): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $journey->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $data = $this->validateJourney($request);

        try {
            $this->syncPricingJourney->handle($team, $product, $data, $journey);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['steps' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pricing journey updated']);

        return back();
    }

    /**
     * Archive a journey — hides it from the "create subscription" picker
     * without deleting it, since any subscription_schedules row referencing
     * it (informationally) must keep resolving (schema.md §3, no hard
     * deletes once referenced).
     */
    public function archive(Request $request, Product $product, PricingJourney $journey): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($product->team_id === $team->id && $journey->product_id === $product->id, 404);

        Gate::authorize('managePrices', $team);

        $journey->update(['status' => 'archived']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pricing journey archived']);

        return back();
    }

    /**
     * @return array{name: string, description: string|null, steps: array<int, array<string, mixed>>}
     */
    private function validateJourney(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.price_id' => ['required', 'integer'],
            'steps.*.quantity' => ['nullable', 'integer', 'min:1'],
            'steps.*.duration_interval' => ['nullable', 'in:day,week,month,year'],
            'steps.*.duration_count' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}
