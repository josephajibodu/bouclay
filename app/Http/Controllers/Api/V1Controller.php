<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use App\Support\Api\ResolvesPublicIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class V1Controller extends Controller
{
    use ResolvesPublicIds;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resource(array $data, int $status = 200, ?Request $request = null): JsonResponse
    {
        return ApiResponse::resource($data, $status, $request ?? request());
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array<string, mixed>|null  $pagination
     */
    protected function collection(array $data, ?array $pagination = null, ?Request $request = null): JsonResponse
    {
        return ApiResponse::collection($data, $pagination, $request ?? request());
    }

    protected function context(Request $request): ApiContext
    {
        return $this->apiContext($request);
    }
}
