<?php

namespace App\Services\Gateways\Flutterwave;

use App\Services\Gateways\GatewayException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Flutterwave's v3 REST API, scoped to a team's own BYOK
 * secret key.
 *
 * Like Paystack there's one host for both environments — the `FLWSECK_TEST-`
 * prefix marks a test key — and auth is a plain bearer token. Every response
 * is wrapped in `{status, message, data}`, where `status` is the string
 * "success", not a boolean.
 */
class FlutterwaveClient
{
    private const string GATEWAY = 'Flutterwave';

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

        if (in_array($response->status(), [401, 403], true)) {
            throw GatewayException::invalidCredentials(
                self::GATEWAY,
                (string) ($body['message'] ?? 'the key was not accepted'),
            );
        }

        if (($body['status'] ?? null) !== 'success') {
            throw GatewayException::unknown(
                self::GATEWAY,
                (string) ($body['message'] ?? 'Flutterwave request failed'),
            );
        }

        return $body;
    }

    private function baseUrl(): string
    {
        return (string) config('services.flutterwave.url', 'https://api.flutterwave.com');
    }
}
