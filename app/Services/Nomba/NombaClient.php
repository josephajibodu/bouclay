<?php

namespace App\Services\Nomba;

use App\Enums\ApiKeyMode;
use App\Exceptions\Nomba\NombaConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NombaClient
{
    /**
     * Exchange accountId/clientId/clientSecret for an access token.
     *
     * Nomba access tokens live for 30 minutes; we cache ours for slightly
     * less than that so callers never have to think about expiry.
     *
     * @throws NombaConnectionException
     */
    public function accessToken(ApiKeyMode $mode, string $accountId, string $clientId, string $clientSecret): string
    {
        $cacheKey = 'nomba:access_token:'.hash('sha256', "{$mode->value}:{$accountId}:{$clientId}:{$clientSecret}");

        return Cache::remember($cacheKey, now()->addMinutes(25), function () use ($mode, $accountId, $clientId, $clientSecret) {
            return $this->issueAccessToken($mode, $accountId, $clientId, $clientSecret);
        });
    }

    /**
     * Verify a set of credentials are accepted by Nomba, without caching
     * the result — used when a user is connecting or re-validating.
     *
     * @throws NombaConnectionException
     */
    public function verifyCredentials(ApiKeyMode $mode, string $accountId, string $clientId, string $clientSecret): void
    {
        $this->issueAccessToken($mode, $accountId, $clientId, $clientSecret);
    }

    /**
     * @throws NombaConnectionException
     */
    private function issueAccessToken(ApiKeyMode $mode, string $accountId, string $clientId, string $clientSecret): string
    {
        try {
            $response = Http::baseUrl($this->baseUrlFor($mode))
                ->timeout(10)
                ->withHeaders(['accountId' => $accountId])
                ->post('/v1/auth/token/issue', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Throwable) {
            throw NombaConnectionException::unreachable();
        }

        $body = $response->json();
        $code = $body['code'] ?? null;

        if ($code !== '00') {
            $description = $body['description'] ?? 'Unknown error';

            throw in_array($code, ['01', '401'], true)
                ? NombaConnectionException::invalidCredentials($description)
                : NombaConnectionException::unknown($description);
        }

        $accessToken = $body['data']['access_token'] ?? null;

        if (! is_string($accessToken)) {
            throw NombaConnectionException::unknown('Malformed response from Nomba');
        }

        return $accessToken;
    }

    private function baseUrlFor(ApiKeyMode $mode): string
    {
        return match ($mode) {
            ApiKeyMode::Test => config('services.nomba.sandbox_url', 'https://sandbox.nomba.com'),
            ApiKeyMode::Live => config('services.nomba.production_url', 'https://api.nomba.com'),
        };
    }
}
