<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureIdempotency
{
    private const int STALE_LOCK_MINUTES = 5;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PATCH'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || trim($key) === '') {
            return ApiResponse::error(
                'invalid_field',
                'Idempotency-Key header is required for this request.',
                400,
                request: $request,
            );
        }

        /** @var ApiContext $context */
        $context = $request->attributes->get('api_context');

        $requestHash = $this->requestFingerprint($request);

        $recordOrResponse = $this->acquireLock($context->team->id, $key, $requestHash, $request);

        if ($recordOrResponse instanceof Response) {
            return $recordOrResponse;
        }

        $record = $recordOrResponse;

        $response = $next($request);

        if ($response instanceof Response && $response->getStatusCode() < 500) {
            $body = json_decode($response->getContent(), true);

            if (is_array($body)) {
                $record->forceFill([
                    'response_code' => $response->getStatusCode(),
                    'response_body' => $body,
                    'locked_at' => null,
                ])->save();
            }
        } else {
            $record->forceFill(['locked_at' => null])->save();
        }

        return $response;
    }

    private function requestFingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            $request->getContent(),
        ]));
    }

    private function acquireLock(int $teamId, string $key, string $requestHash, Request $request): IdempotencyKey|Response
    {
        return DB::transaction(function () use ($teamId, $key, $requestHash, $request): IdempotencyKey|Response {
            $existing = IdempotencyKey::query()
                ->where('team_id', $teamId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $replay = $this->replayOrConflict($existing, $requestHash, $request);

                if ($replay instanceof Response) {
                    return $replay;
                }

                $existing->forceFill(['locked_at' => now()])->save();

                return $existing;
            }

            try {
                return IdempotencyKey::query()->create([
                    'team_id' => $teamId,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'locked_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = IdempotencyKey::query()
                    ->where('team_id', $teamId)
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->firstOrFail();

                $replay = $this->replayOrConflict($existing, $requestHash, $request);

                if ($replay instanceof Response) {
                    return $replay;
                }

                $existing->forceFill(['locked_at' => now()])->save();

                return $existing;
            }
        });
    }

    private function replayOrConflict(IdempotencyKey $existing, string $requestHash, Request $request): IdempotencyKey|Response
    {
        if ($existing->request_hash !== $requestHash) {
            return ApiResponse::error(
                'idempotency_conflict',
                'Idempotency key was already used with a different request body.',
                409,
                request: $request,
            );
        }

        if ($existing->response_code !== null && $existing->response_body !== null) {
            return response()->json($existing->response_body, $existing->response_code);
        }

        if ($existing->locked_at !== null && $existing->locked_at->gt(now()->subMinutes(self::STALE_LOCK_MINUTES))) {
            return ApiResponse::error(
                'idempotency_conflict',
                'A request with this idempotency key is already in progress.',
                409,
                request: $request,
            );
        }

        return $existing;
    }
}
