<?php

namespace App\Http\Middleware;

use App\Enums\ApiKeyKind;
use App\Models\ApiKey;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return ApiResponse::error(
                'authentication_failed',
                'Missing or invalid API key.',
                401,
                request: $request,
            );
        }

        $apiKey = ApiKey::query()
            ->where('hashed_secret', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->first();

        if ($apiKey === null) {
            return ApiResponse::error(
                'authentication_failed',
                'Missing or invalid API key.',
                401,
                request: $request,
            );
        }

        if ($apiKey->kind === ApiKeyKind::Publishable) {
            return ApiResponse::error(
                'permission_denied',
                'Publishable keys cannot be used for server-side API requests.',
                403,
                request: $request,
            );
        }

        $apiKey->forceFill(['last_used_at' => now()])->save();

        $apiKey->loadMissing('team');

        $request->attributes->set('api_context', new ApiContext(
            team: $apiKey->team,
            mode: $apiKey->mode,
            apiKey: $apiKey,
        ));

        return $next($request);
    }
}
