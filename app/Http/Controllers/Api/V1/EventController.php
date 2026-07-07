<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1Controller;
use App\Models\Event;
use App\Support\Api\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends V1Controller
{
    public function index(Request $request): JsonResponse
    {
        $context = $this->context($request);

        $query = $context->team->events();

        if ($request->filled('type')) {
            $query->where('type', (string) $request->query('type'));
        }

        $result = CursorPaginator::paginate($query, $request);

        return $this->collection(
            collect($result['items'])->map(fn (Event $event) => $event->toApiObject())->all(),
            $result['pagination'],
            $request,
        );
    }

    public function show(Request $request, string $event): JsonResponse
    {
        $context = $this->context($request);

        $model = $context->team->events()->where('public_id', $event)->firstOrFail();

        return $this->resource($model->toApiObject(), request: $request);
    }
}
