<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CreatePrice;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Product;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends V1Controller
{
    public function __construct(private readonly CreatePrice $createPrice)
    {
        //
    }

    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);
        $status = $request->query('status');

        $query = $context->team->products();

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'active') {
            $query->where('status', CatalogStatus::Active);
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Product $product) => $product->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function store(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:255'],
            'customData' => ['nullable', 'array'],
            'price' => ['nullable', 'array'],
            'price.name' => ['nullable', 'string', 'max:255'],
            'price.type' => ['required_with:price', 'in:one_time,recurring'],
            'price.pricingModel' => ['required_with:price', 'in:standard,graduated'],
            'price.unitAmount' => ['nullable', 'numeric', 'min:0'],
            'price.currency' => ['nullable', 'string', 'size:3'],
            'price.billingInterval' => ['required_with:price', 'required_if:price.type,recurring', 'in:month,week,year,day'],
            'price.billingFrequency' => ['nullable', 'integer', 'min:1'],
        ]);

        $product = $context->team->products()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'custom_data' => $data['customData'] ?? null,
            'status' => CatalogStatus::Active,
        ]);

        if (! empty($data['price'])) {
            $priceData = [
                'name' => $data['price']['name'] ?? null,
                'type' => $data['price']['type'],
                'pricing_model' => $data['price']['pricingModel'],
                'unit_amount' => $data['price']['unitAmount'] ?? null,
                'currency' => $data['price']['currency'] ?? $context->team->default_currency,
                'billing_interval' => $data['price']['billingInterval'] ?? null,
                'billing_frequency' => $data['price']['billingFrequency'] ?? 1,
            ];

            $this->createPrice->handle($product, $priceData);
        }

        $product->load(['prices' => fn ($q) => $q->where('status', CatalogStatus::Active)]);

        return $this->resource([
            ...$product->toApiObject(),
            'priceIds' => $product->prices->pluck('public_id')->all(),
        ], 201, $request);
    }

    public function show(Request $request, string $product): JsonResponse
    {
        $context = $this->context($request);

        /** @var Product $model */
        $model = $this->findProduct($context->team, $product);
        $model->load(['prices', 'trialOffers']);

        return $this->resource([
            ...$model->toApiObject(),
            'priceIds' => $model->prices->pluck('public_id')->all(),
            'trialOfferIds' => $model->trialOffers->pluck('public_id')->all(),
        ], request: $request);
    }

    public function update(Request $request, string $product): JsonResponse
    {
        $context = $this->context($request);

        /** @var Product $model */
        $model = $this->findProduct($context->team, $product);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(CatalogStatus::class)],
            'customData' => ['nullable', 'array'],
        ]);

        $model->update([
            'name' => $data['name'] ?? $model->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $model->description,
            'category' => array_key_exists('category', $data) ? $data['category'] : $model->category,
            'status' => isset($data['status']) ? CatalogStatus::from($data['status']) : $model->status,
            'custom_data' => array_key_exists('customData', $data) ? $data['customData'] : $model->custom_data,
        ]);

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    public function archive(Request $request, string $product): JsonResponse
    {
        $context = $this->context($request);

        /** @var Product $model */
        $model = $this->findProduct($context->team, $product);

        if ($model->trashed()) {
            throw ValidationException::withMessages(['product' => 'Product is already archived.']);
        }

        $model->delete();

        return $this->resource($model->toApiObject(), request: $request);
    }
}
