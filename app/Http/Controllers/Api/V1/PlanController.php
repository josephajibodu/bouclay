<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlanStatus;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Plan;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The named tiers under a product ("Premium") — thin identity + lifecycle
 * objects; the billable variants live on /prices with a planId
 * (schema.md §3).
 */
class PlanController extends V1Controller
{
    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $query = $context->team->plans()->with('product');

        if ($request->filled('productId')) {
            $product = $this->findProduct($context->team, (string) $request->query('productId'));
            $query->where('product_id', $product->id);
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', (string) $request->query('status'));
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Plan $plan) => $plan->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function store(Request $request, string $product): JsonResponse
    {
        $context = $this->context($request);

        $productModel = $this->findProduct($context->team, $product);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(PlanStatus::class)],
            'customData' => ['nullable', 'array'],
        ]);

        $plan = $productModel->plans()->create([
            'team_id' => $context->team->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? PlanStatus::Active,
            'custom_data' => $data['customData'] ?? null,
        ]);

        return $this->resource($plan->toApiObject(), 201, $request);
    }

    public function show(Request $request, string $plan): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findPlan($context->team, $plan);
        $model->load(['prices' => fn ($query) => $query->where('purchasable', true)]);

        return $this->resource([
            ...$model->toApiObject(),
            'priceIds' => $model->prices->pluck('public_id')->all(),
        ], request: $request);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findPlan($context->team, $plan);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(PlanStatus::class)],
            'customData' => ['sometimes', 'nullable', 'array'],
        ]);

        $model->update([
            'name' => $data['name'] ?? $model->name,
            'code' => array_key_exists('code', $data) ? $data['code'] : $model->code,
            'status' => isset($data['status']) ? PlanStatus::from($data['status']) : $model->status,
            'custom_data' => array_key_exists('customData', $data) ? $data['customData'] : $model->custom_data,
        ]);

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }

    /**
     * Archive a plan — its prices immediately stop being purchasable for
     * new subscriptions (the draft/archived-plan rule); existing
     * subscribers are untouched.
     */
    public function archive(Request $request, string $plan): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findPlan($context->team, $plan);

        if ($model->status === PlanStatus::Archived) {
            throw ValidationException::withMessages(['plan' => 'Plan is already archived.']);
        }

        $model->update(['status' => PlanStatus::Archived]);

        return $this->resource($model->fresh()->toApiObject(), request: $request);
    }
}
