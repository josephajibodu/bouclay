<?php

namespace App\Http\Controllers\Discounts;

use App\Enums\CatalogStatus;
use App\Enums\DiscountType;
use App\Enums\PlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Discounts\StoreDiscountRequest;
use App\Http\Requests\Discounts\UpdateDiscountRequest;
use App\Models\Discount;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard CRUD for discounts (schema.md §7). Eligibility authoring follows
 * the `eligible_price_ids`-wins rule; the actual redemption/application lives
 * in the billing engine (CreateInvoice / RedeemDiscount).
 */
class DiscountController extends Controller
{
    /**
     * List the team's discounts, plus the plan/price options the create-and-edit
     * drawer needs for eligibility targeting.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewDiscounts', $team);

        $discounts = $team->discounts()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Discount $discount) => $discount->toDashboardArray());

        return Inertia::render('discounts/index', [
            'discounts' => $discounts,
            'canManage' => $request->user()->toTeamPermissions($team)->canManageDiscounts,
            'defaultCurrency' => $team->default_currency,
            ...$this->eligibilityOptions($team),
        ]);
    }

    /**
     * Create a discount.
     */
    public function store(StoreDiscountRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageDiscounts', $team);

        $team->discounts()->create($this->attributes($team, $request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Discount created.']);

        return back();
    }

    /**
     * Update a discount. Financial fields stay editable — a discount is not an
     * immutable ledger row like a price; existing redemptions keep whatever the
     * invoice already recorded (line-level discount_amount is the source of truth).
     */
    public function update(UpdateDiscountRequest $request, Discount $discount): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($discount->team_id === $team->id, 404);

        Gate::authorize('manageDiscounts', $team);

        $discount->update($this->attributes($team, $request->validated()));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Discount updated.']);

        return back();
    }

    /**
     * Delete a discount if it was never redeemed; otherwise deactivate it so
     * the redemption history (and its FK) survives.
     */
    public function destroy(Request $request, Discount $discount): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($discount->team_id === $team->id, 404);

        Gate::authorize('manageDiscounts', $team);

        if ($discount->redemptions()->exists()) {
            $discount->update(['active' => false]);

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Discount deactivated — it has redemption history.']);

            return back();
        }

        $discount->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Discount deleted.']);

        return back();
    }

    /**
     * Map the validated request into model attributes: amounts to minor units,
     * percentage to a 2-decimal string, and eligibility arrays filtered to ids
     * the team actually owns (so a spoofed id can't widen a promo).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(Team $team, array $data): array
    {
        $type = DiscountType::from((string) $data['type']);

        return [
            'code' => $data['code'] ?? null,
            'type' => $type,
            'amount' => $type === DiscountType::Flat ? (int) round(((float) $data['amount']) * 100) : null,
            'percentage' => $type === DiscountType::Percentage ? number_format((float) $data['percentage'], 2, '.', '') : null,
            'currency' => $type === DiscountType::Flat ? $data['currency'] : null,
            'duration' => $data['duration'],
            'duration_in_intervals' => $data['duration'] === 'repeating' ? (int) $data['duration_in_intervals'] : null,
            'max_redemptions' => $data['max_redemptions'] ?? null,
            'eligible_plan_ids' => $this->ownedPlanIds($team, $data['eligible_plan_ids'] ?? null),
            'eligible_price_ids' => $this->ownedPriceIds($team, $data['eligible_price_ids'] ?? null),
            'starts_at' => isset($data['starts_at']) ? Carbon::parse((string) $data['starts_at']) : null,
            'expires_at' => isset($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : null,
            'active' => $data['active'] ?? true,
        ];
    }

    /**
     * @param  array<int, int>|null  $ids
     * @return array<int, int>|null
     */
    private function ownedPlanIds(Team $team, ?array $ids): ?array
    {
        if ($ids === null || $ids === []) {
            return null;
        }

        return array_values($team->plans()->whereIn('id', $ids)->pluck('id')->all());
    }

    /**
     * @param  array<int, int>|null  $ids
     * @return array<int, int>|null
     */
    private function ownedPriceIds(Team $team, ?array $ids): ?array
    {
        if ($ids === null || $ids === []) {
            return null;
        }

        return array_values($team->prices()->whereIn('id', $ids)->pluck('id')->all());
    }

    /**
     * The plan + price options for the eligibility pickers.
     *
     * @return array{plans: array<int, array<string, mixed>>, prices: array<int, array<string, mixed>>}
     */
    private function eligibilityOptions(Team $team): array
    {
        $plans = $team->plans()
            ->where('status', PlanStatus::Active)
            ->with('product')
            ->orderBy('name')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'productName' => $plan->product->name,
            ]);

        $prices = $team->prices()
            ->where('status', CatalogStatus::Active)
            ->where('purchasable', true)
            ->whereNotNull('plan_id')
            ->with('plan')
            ->orderBy('name')
            ->get()
            ->map(fn (Price $price) => [
                'id' => $price->id,
                'label' => $price->toPickerLabel(),
                'planName' => $price->plan?->name,
            ]);

        return ['plans' => $plans->all(), 'prices' => $prices->all()];
    }
}
