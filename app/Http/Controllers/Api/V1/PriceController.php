<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CreatePrice;
use App\Enums\CatalogStatus;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Price;
use App\Models\Product;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PriceController extends V1Controller
{
    public function __construct(private readonly CreatePrice $createPrice)
    {
        //
    }

    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $query = $context->team->prices()->with(['product', 'tiers']);

        if ($request->filled('productId')) {
            $product = $this->findProduct($context->team, (string) $request->query('productId'));
            $query->where('product_id', $product->id);
        }

        if ($request->filled('planId')) {
            $plan = $this->findPlan($context->team, (string) $request->query('planId'));
            $query->where('plan_id', $plan->id);
        }

        if ($request->boolean('purchasable')) {
            $query->purchasableForNewSubscriptions();
        }

        if ($request->query('status') === 'archived') {
            $query->where('status', CatalogStatus::Archived);
        } elseif ($request->query('status') !== 'all') {
            $query->where('status', CatalogStatus::Active);
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Price $price) => $price->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function store(Request $request, string $product): JsonResponse
    {
        $context = $this->context($request);

        /** @var Product $productModel */
        $productModel = $this->findProduct($context->team, $product);

        $data = $request->validate([
            'planId' => ['required_if:type,recurring', 'prohibited_if:type,one_time', 'nullable', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:one_time,recurring'],
            'pricingModel' => ['required', 'in:standard,graduated'],
            'unitAmount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billingInterval' => ['required_if:type,recurring', 'in:month,week,year,day'],
            'billingFrequency' => ['nullable', 'integer', 'min:1'],
            'trialLength' => ['nullable', 'integer', 'min:1', 'prohibited_if:type,one_time'],
            'trialUnit' => ['required_with:trialLength', 'nullable', 'in:day,week,month'],
            'trialRequiresPaymentInfo' => ['nullable', 'boolean'],
            'trialOncePerCustomer' => ['nullable', 'boolean'],
            'customData' => ['nullable', 'array'],
        ]);

        $planId = null;

        if (isset($data['planId'])) {
            $plan = $this->findPlan($context->team, (string) $data['planId']);

            if ($plan->product_id !== $productModel->id) {
                throw ValidationException::withMessages([
                    'planId' => 'The plan must belong to the product the price is being created under.',
                ]);
            }

            $planId = $plan->id;
        }

        $price = $this->createPrice->handle($productModel, [
            'plan_id' => $planId,
            'name' => $data['name'] ?? null,
            'type' => $data['type'],
            'pricing_model' => $data['pricingModel'],
            'unit_amount' => $data['unitAmount'] ?? null,
            'currency' => $data['currency'] ?? $context->team->default_currency,
            'billing_interval' => $data['billingInterval'] ?? null,
            'billing_frequency' => $data['billingFrequency'] ?? 1,
            'trial_length' => $data['trialLength'] ?? null,
            'trial_unit' => $data['trialUnit'] ?? null,
            'trial_requires_payment_info' => $data['trialRequiresPaymentInfo'] ?? false,
            'trial_once_per_customer' => $data['trialOncePerCustomer'] ?? true,
            'custom_data' => $data['customData'] ?? null,
        ]);

        return $this->resource($price->load('tiers')->toApiObject(), 201, $request);
    }

    public function show(Request $request, string $price): JsonResponse
    {
        $context = $this->context($request);

        /** @var Price $model */
        $model = $this->findPrice($context->team, $price);
        $model->load(['product', 'tiers']);

        return $this->resource($model->toApiObject(), request: $request);
    }

    public function update(Request $request, string $price): JsonResponse
    {
        $context = $this->context($request);

        /** @var Price $model */
        $model = $this->findPrice($context->team, $price);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'unitAmount' => ['nullable', 'numeric', 'min:0'],
            'customData' => ['nullable', 'array'],
            'status' => ['nullable', 'in:active,archived'],
        ]);

        if (isset($data['unitAmount']) && $model->hasBeenUsed()) {
            throw ValidationException::withMessages([
                'unitAmount' => 'This price has active subscribers — create a new price instead of editing this one.',
            ]);
        }

        $updates = [];

        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }

        if (array_key_exists('customData', $data)) {
            $updates['custom_data'] = $data['customData'];
        }

        if (isset($data['unitAmount'])) {
            $updates['unit_amount'] = (int) round($data['unitAmount'] * 100);
        }

        if (isset($data['status'])) {
            $updates['status'] = $data['status'] === 'archived'
                ? CatalogStatus::Archived
                : CatalogStatus::Active;
        }

        $model->update($updates);

        return $this->resource($model->fresh()->load('tiers')->toApiObject(), request: $request);
    }

    public function archive(Request $request, string $price): JsonResponse
    {
        $context = $this->context($request);

        /** @var Price $model */
        $model = $this->findPrice($context->team, $price);

        $model->update(['status' => CatalogStatus::Archived]);

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }
}
