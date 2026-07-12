<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreatePrice;
use App\Enums\PlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private readonly CreatePrice $createPrice)
    {
        //
    }

    /**
     * List the current team's product catalog.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewProducts', $team);

        $products = $team->products()
            // Phase-only charge targets (purchasable=false) never surface
            // in the Products list (schema.md §3).
            ->with(['prices' => fn ($query) => $query->where('status', 'active')->where('purchasable', true)])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'category' => $product->category,
                'status' => $product->status,
                'createdAt' => $product->created_at?->toISOString(),
                'prices' => $product->prices->map(fn ($price) => $price->toCatalogArray())->all(),
            ]);

        return Inertia::render('catalog/products', [
            'products' => $products,
            'categories' => $products->pluck('category')->filter()->unique()->values(),
            'canManage' => $request->user()->toTeamPermissions($team)->canManageProducts,
        ]);
    }

    /**
     * Render the product creation page.
     */
    public function create(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageProducts', $team);

        return Inertia::render('catalog/create', [
            'defaultCurrency' => $team->default_currency,
        ]);
    }

    /**
     * Create a product, optionally with its first price. A recurring first
     * price needs a plan to be a variant of (schema.md §3), so one is
     * created alongside — named by `price.plan_name`, defaulting to the
     * product's own name (the common one-tier starting point).
     */
    public function store(StoreProductRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageProducts', $team);

        $product = $team->products()->create([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'category' => $request->validated('category'),
        ]);

        $priceData = $request->validated('price');

        if ($priceData) {
            $priceData['currency'] ??= $team->default_currency;

            if (($priceData['type'] ?? null) === 'recurring') {
                $plan = $product->plans()->create([
                    'team_id' => $team->id,
                    'name' => $priceData['plan_name'] ?? $product->name,
                    'status' => PlanStatus::Active,
                ]);

                $priceData['plan_id'] = $plan->id;
            }

            unset($priceData['plan_name']);

            $this->createPrice->handle($product, $priceData);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$product->name} created"]);

        return to_route('catalog.products.show', $product);
    }

    /**
     * Show the product detail hub — info, pricing, and metadata on one
     * scrollable page. (Plans CRUD lands here in V2-1; trial config now
     * lives on prices themselves.)
     */
    public function show(Request $request, Product $product): Response
    {
        abort_unless($product->team_id === $request->user()->currentTeam->id, 404);

        $team = $request->user()->currentTeam;

        Gate::authorize('viewProducts', $team);

        $product->load([
            'plans' => fn ($query) => $query->orderBy('created_at'),
            'prices' => fn ($query) => $query->with(['tiers', 'paymentLink', 'phases.chargePrice'])->orderBy('created_at'),
        ]);

        $permissions = $request->user()->toTeamPermissions($team);

        return Inertia::render('catalog/show', [
            'product' => [
                'id' => $product->id,
                'publicId' => $product->public_id,
                'name' => $product->name,
                'description' => $product->description,
                'category' => $product->category,
                'websiteUrl' => $product->website_url,
                'status' => $product->status,
                'customData' => $product->custom_data,
                'createdAt' => $product->created_at?->toISOString(),
            ],
            'plans' => $product->plans->map(fn ($plan) => [
                'id' => $plan->id,
                'publicId' => $plan->public_id,
                'code' => $plan->code,
                'name' => $plan->name,
                'status' => $plan->status,
            ])->all(),
            'prices' => $product->prices->map(fn ($price) => $price->toCatalogArray())->all(),
            'permissions' => [
                'canManageProducts' => $permissions->canManageProducts,
                'canManagePlans' => $permissions->canManagePlans,
                'canManagePrices' => $permissions->canManagePrices,
            ],
        ]);
    }

    /**
     * Update a product's details or status.
     */
    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        abort_unless($product->team_id === $request->user()->currentTeam->id, 404);

        Gate::authorize('manageProducts', $request->user()->currentTeam);

        $product->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$product->name} updated"]);

        return back();
    }
}
