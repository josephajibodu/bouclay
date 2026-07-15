<?php

namespace App\Services\Gateways\Nomba;

use App\Enums\ApiKeyMode;
use App\Services\Gateways\GatewayException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class NombaClient
{
    private const string GATEWAY = 'Nomba';

    /**
     * Exchange accountId/clientId/clientSecret for an access token.
     *
     * Nomba access tokens live for 30 minutes; we cache ours for slightly
     * less than that so callers never have to think about expiry.
     *
     * @throws GatewayException
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
     * @throws GatewayException
     */
    public function verifyCredentials(ApiKeyMode $mode, string $accountId, string $clientId, string $clientSecret): void
    {
        $this->issueAccessToken($mode, $accountId, $clientId, $clientSecret);
    }

    /**
     * @throws GatewayException
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
            throw GatewayException::unreachable(self::GATEWAY);
        }

        $body = $response->json();
        $code = $body['code'] ?? null;

        if ($code !== '00') {
            $description = $body['description'] ?? 'Unknown error';

            throw in_array($code, ['01', '401'], true)
                ? GatewayException::invalidCredentials(self::GATEWAY, $description)
                : GatewayException::unknown(self::GATEWAY, $description);
        }

        $accessToken = $body['data']['access_token'] ?? null;

        if (! is_string($accessToken)) {
            throw GatewayException::unknown(self::GATEWAY, 'Malformed response from Nomba');
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
