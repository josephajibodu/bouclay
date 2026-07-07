<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreatePrice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Models\Product;
use App\Models\TrialOffer;
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
            ->with(['prices' => fn ($query) => $query->where('status', 'active')])
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
     * Create a product, optionally with its first price. Trials are
     * created afterward, from the product page — see CATALOG_DESIGN.md
     * §7.1 (revised): a trial needs a real trial price to reference, which
     * doesn't exist until at least one price has been created.
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

            $this->createPrice->handle($product, $priceData);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$product->name} created"]);

        return to_route('catalog.products.show', $product);
    }

    /**
     * Show the product detail hub — info, pricing, trials, and metadata
     * on one scrollable page.
     */
    public function show(Request $request, Product $product): Response
    {
        abort_unless($product->team_id === $request->user()->currentTeam->id, 404);

        $team = $request->user()->currentTeam;

        Gate::authorize('viewProducts', $team);

        $product->load(['prices' => fn ($query) => $query->with(['tiers', 'paymentLink'])->orderBy('created_at')]);

        $trials = TrialOffer::query()
            ->where('product_id', $product->id)
            ->with(['trialPrice', 'transitionPrice', 'transitionProduct', 'paymentLink'])
            ->get();

        // Other active products (with their active prices) — populates the
        // "transition to a different product" picker in the trial drawer.
        $otherProducts = $team->products()
            ->where('id', '!=', $product->id)
            ->where('status', 'active')
            ->with(['prices' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get()
            ->map(fn (Product $other) => [
                'id' => $other->id,
                'name' => $other->name,
                'prices' => $other->prices->map(fn ($price) => [
                    'id' => $price->id,
                    'label' => $price->toPickerLabel(),
                ])->all(),
            ]);

        return Inertia::render('catalog/show', [
            'product' => [
                'id' => $product->id,
                'publicId' => $product->public_id,
                'name' => $product->name,
                'description' => $product->description,
                'category' => $product->category,
                'status' => $product->status,
                'customData' => $product->custom_data,
                'createdAt' => $product->created_at?->toISOString(),
            ],
            'prices' => $product->prices->map(fn ($price) => $price->toCatalogArray())->all(),
            'trials' => $trials->map(fn (TrialOffer $trial) => $trial->toCatalogArray())->all(),
            'otherProducts' => $otherProducts,
            'permissions' => [
                'canManageProducts' => $request->user()->toTeamPermissions($team)->canManageProducts,
                'canManagePrices' => $request->user()->toTeamPermissions($team)->canManagePrices,
                'canManageTrialOffers' => $request->user()->toTeamPermissions($team)->canManageTrialOffers,
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
