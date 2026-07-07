<?php

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function resource(array $data, int $status = 200, ?Request $request = null): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => self::meta($request),
        ], $status);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @param  array<string, mixed>|null  $pagination
     */
    public static function collection(array $data, ?array $pagination = null, ?Request $request = null): JsonResponse
    {
        $meta = self::meta($request);

        if ($pagination !== null) {
            $meta['pagination'] = $pagination;
        }

        return response()->json([
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    /**
     * @param  list<array<string, string>>|null  $errors
     */
    public static function error(
        string $code,
        string $detail,
        int $status,
        string $type = 'request_error',
        ?array $errors = null,
        ?Request $request = null,
    ): JsonResponse {
        $error = [
            'type' => $type,
            'code' => $code,
            'detail' => $detail,
        ];

        if ($errors !== null) {
            $error['errors'] = $errors;
        }

        return response()->json([
            'error' => $error,
            'meta' => self::meta($request),
        ], $status);
    }

    /**
     * @return array<string, string>
     */
    private static function meta(?Request $request): array
    {
        $requestId = $request?->attributes->get('api_request_id');

        if (! is_string($requestId) || $requestId === '') {
            $requestId = 'req_'.Str::uuid()->toString();
        }

        return ['requestId' => $requestId];
    }
}
