<?php

namespace App\Services\Gateways\Paystack;

use App\Services\Gateways\GatewayException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Paystack's REST API, always scoped to a team's own BYOK
 * secret key.
 *
 * Two things differ from Nomba in ways the driver boundary has to absorb:
 * there is one base URL for both environments — the key's `sk_test_`/`sk_live_`
 * prefix decides which is which — and auth is a plain bearer token, so there's
 * no token-issue round trip or cache to manage.
 */
class PaystackClient
{
    private const string GATEWAY = 'Paystack';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    public function post(string $secretKey, string $path, array $payload): array
    {
        return $this->send($secretKey, 'post', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    public function get(string $secretKey, string $path, array $query = []): array
    {
        return $this->send($secretKey, 'get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    private function send(string $secretKey, string $method, string $path, array $data): array
    {
        if ($secretKey === '') {
            throw GatewayException::invalidCredentials(self::GATEWAY, 'no secret key is saved for this mode');
        }

        try {
            $request = Http::baseUrl($this->baseUrl())
                ->timeout(20)
                ->withToken($secretKey)
                ->acceptJson();

            /** @var Response $response */
            $response = $method === 'get'
                ? $request->get($path, $data)
                : $request->post($path, $data);
        } catch (\Throwable) {
            throw GatewayException::unreachable(self::GATEWAY);
        }

        $body = $response->json() ?? [];

        // Paystack rejects a bad key with 401 and signals every other outcome
        // with a `status` boolean; a false `status` on 200 is a real business
        // failure (declined, already refunded), not a transport problem.
        if ($response->status() === 401) {
            throw GatewayException::invalidCredentials(
                self::GATEWAY,
                (string) ($body['message'] ?? 'the key was not accepted'),
            );
        }

        if (($body['status'] ?? false) !== true) {
            throw GatewayException::unknown(
                self::GATEWAY,
                (string) ($body['message'] ?? 'Paystack request failed'),
            );
        }

        return $body;
    }

    /**
     * One host for both environments — unlike Nomba, Paystack tells test from
     * live by the key prefix alone.
     */
    private function baseUrl(): string
    {
        return (string) config('services.paystack.url', 'https://api.paystack.co');
    }
}
