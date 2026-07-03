<?php

namespace App\Http\Controllers\Catalog;

use App\Actions\Catalog\CreatePrice;
use App\Actions\Catalog\CreateTrialOffer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductRequest;
use App\Http\Requests\Catalog\UpdateProductRequest;
use App\Models\Product;
use App\Models\TrialOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly CreatePrice $createPrice,
        private readonly CreateTrialOffer $createTrialOffer,
    ) {
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
            ->with(['prices' => fn ($query) => $query->customerFacing()->where('status', 'active')])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'category' => $product->category,
                'status' => $product->status,
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
     * Create a product, optionally with its first price and a free trial.
     */
    public function store(StoreProductRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageProducts', $team);

        $product = DB::transaction(function () use ($team, $request) {
            $product = $team->products()->create([
                'name' => $request->validated('name'),
                'description' => $request->validated('description'),
                'category' => $request->validated('category'),
            ]);

            $priceData = $request->validated('price');

            if ($priceData) {
                $priceData['currency'] ??= $team->default_currency;

                $price = $this->createPrice->handle($product, $priceData);

                $trialData = $request->validated('trial');

                if ($trialData) {
                    $this->createTrialOffer->handle($price, $trialData);
                }
            }

            return $product;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$product->name} created"]);

        return to_route('catalog.products.show', [$team, $product]);
    }

    /**
     * Show the product detail hub — overview, pricing, and trials.
     *
     * `$current_team` isn't used directly — see the same note on
     * ApiKeyController::destroy for why it must stay in the signature.
     */
    public function show(Request $request, string $current_team, Product $product): Response
    {
        abort_unless($product->team_id === $request->user()->currentTeam->id, 404);

        $team = $request->user()->currentTeam;

        Gate::authorize('viewProducts', $team);

        $product->load(['prices' => fn ($query) => $query->customerFacing()->with('tiers')->orderBy('created_at')]);

        $trialsByPrice = TrialOffer::query()
            ->where('product_id', $product->id)
            ->with('trialPrice')
            ->get()
            ->keyBy('transition_price_id');

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
            'prices' => $product->prices
                ->map(fn ($price) => $price->toCatalogArray($trialsByPrice->get($price->id)))
                ->all(),
            'permissions' => [
                'canManageProducts' => $request->user()->toTeamPermissions($team)->canManageProducts,
                'canManagePrices' => $request->user()->toTeamPermissions($team)->canManagePrices,
                'canManageTrialOffers' => $request->user()->toTeamPermissions($team)->canManageTrialOffers,
            ],
        ]);
    }

    /**
     * Update a product's details or status.
     *
     * `$current_team` isn't used directly — see the same note on
     * ApiKeyController::destroy for why it must stay in the signature.
     */
    public function update(UpdateProductRequest $request, string $current_team, Product $product): RedirectResponse
    {
        abort_unless($product->team_id === $request->user()->currentTeam->id, 404);

        Gate::authorize('manageProducts', $request->user()->currentTeam);

        $product->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$product->name} updated"]);

        return back();
    }
}
