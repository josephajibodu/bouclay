<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1Controller;
use App\Models\Discount;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read access to the team's discounts (schema.md §7). Authoring stays in the
 * dashboard; the API exposes them so integrators can reference a discount's
 * definition and redemption state.
 */
class DiscountController extends V1Controller
{
    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $query = $context->team->discounts();

        if ($request->filled('active') && $request->query('active') !== 'all') {
            $query->where('active', filter_var($request->query('active'), FILTER_VALIDATE_BOOL));
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Discount $discount) => $discount->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function show(Request $request, string $discount): JsonResponse
    {
        $context = $this->context($request);

        $model = $this->findDiscount($context->team, $discount);

        return $this->resource($model->toApiObject(), request: $request);
    }
}
