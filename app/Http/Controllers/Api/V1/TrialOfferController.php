<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CreateTrialOffer;
use App\Http\Controllers\Api\V1Controller;
use App\Models\Product;
use App\Models\TrialOffer;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialOfferController extends V1Controller
{
    public function __construct(private readonly CreateTrialOffer $createTrialOffer)
    {
        //
    }

    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $query = TrialOffer::query()
            ->where('team_id', $context->team->id)
            ->with(['product', 'trialPrice', 'transitionPrice', 'transitionProduct']);

        if ($request->filled('productId')) {
            $product = $this->findProduct($context->team, (string) $request->query('productId'));
            $query->where('product_id', $product->id);
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (TrialOffer $trial) => $trial->toApiObject())->all(),
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
            'name' => ['required', 'string', 'max:255'],
            'trialPriceId' => ['required', 'string'],
            'transitionPriceId' => ['required', 'string'],
            'transitionToDifferentProduct' => ['nullable', 'boolean'],
            'transitionProductId' => ['nullable', 'string'],
            'durationIterations' => ['required', 'integer', 'min:1'],
        ]);

        $trialPrice = $this->findPrice($context->team, $data['trialPriceId']);
        $transitionPrice = $this->findPrice($context->team, $data['transitionPriceId']);

        $transitionProductId = $productModel->id;

        if ($data['transitionToDifferentProduct'] ?? false) {
            $transitionProduct = $this->findProduct($context->team, (string) $data['transitionProductId']);
            $transitionProductId = $transitionProduct->id;
        }

        $trial = $this->createTrialOffer->handle($productModel, [
            'name' => $data['name'],
            'trial_price_id' => $trialPrice->id,
            'transition_to_different_product' => $data['transitionToDifferentProduct'] ?? false,
            'transition_product_id' => $transitionProductId,
            'transition_price_id' => $transitionPrice->id,
            'duration_iterations' => $data['durationIterations'],
        ]);

        return $this->resource($trial->load(['trialPrice', 'transitionPrice', 'product'])->toApiObject(), 201, $request);
    }

    public function show(Request $request, string $trialOffer): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findTrialOffer($context->team, $trialOffer);
        $model->load(['product', 'trialPrice', 'transitionPrice', 'transitionProduct']);

        return $this->resource($model->toApiObject(), request: $request);
    }

    public function update(Request $request, string $trialOffer): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findTrialOffer($context->team, $trialOffer);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'trialPriceId' => ['sometimes', 'string'],
            'transitionPriceId' => ['sometimes', 'string'],
            'transitionToDifferentProduct' => ['nullable', 'boolean'],
            'transitionProductId' => ['nullable', 'string'],
            'durationIterations' => ['sometimes', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
        ]);

        $updates = [];

        if (isset($data['name'])) {
            $updates['name'] = $data['name'];
        }

        if (isset($data['trialPriceId'])) {
            $updates['trial_price_id'] = $this->findPrice($context->team, $data['trialPriceId'])->id;
        }

        if (isset($data['transitionPriceId'])) {
            $updates['transition_price_id'] = $this->findPrice($context->team, $data['transitionPriceId'])->id;
        }

        if (array_key_exists('transitionToDifferentProduct', $data)) {
            $updates['transition_to_different_product'] = (bool) $data['transitionToDifferentProduct'];
        }

        if (isset($data['transitionProductId'])) {
            $updates['transition_product_id'] = $this->findProduct($context->team, $data['transitionProductId'])->id;
        }

        if (isset($data['durationIterations'])) {
            $updates['duration_iterations'] = $data['durationIterations'];
        }

        if (array_key_exists('active', $data)) {
            $updates['active'] = (bool) $data['active'];
        }

        $model->update($updates);

        return $this->resource($model->fresh()->load(['trialPrice', 'transitionPrice', 'product'])->toApiObject(), request: $request);
    }
}
