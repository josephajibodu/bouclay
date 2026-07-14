<?php

namespace App\Services\Nomba;

use App\Enums\ApiKeyMode;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Models\TeamProcessorConnection;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Nomba's hosted-checkout + tokenised-card endpoints,
 * always scoped to a team's own BYOK credentials for a given mode.
 *
 * Verified against Nomba docs (CUSTOMERS_DESIGN §10.3): checkout is a hosted
 * full-redirect page; a card is tokenised as a byproduct of a paid order with
 * `tokenizeCard: true`, and the token comes back via webhook or the
 * tokenised-card list endpoint.
 */
class NombaCheckout
{
    public function __construct(private readonly NombaClient $client)
    {
        //
    }

    /**
     * Create a hosted checkout order and return the link to redirect to.
     *
     * @param  array<string, mixed>  $order
     * @return array{checkoutLink: string, orderReference: string}
     *
     * @throws NombaConnectionException
     */
    public function createOrder(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, bool $tokenizeCard = true): array
    {
        $body = $this->post($connection, $mode, '/v1/checkout/order', [
            'order' => $this->scopeOrderToSubaccount($order, $connection, $mode),
            'tokenizeCard' => $tokenizeCard,
        ]);

        $data = $body['data'] ?? [];

        if (! isset($data['checkoutLink'], $data['orderReference'])) {
            throw NombaConnectionException::unknown('Nomba did not return a checkout link.');
        }

        return [
            'checkoutLink' => $data['checkoutLink'],
            'orderReference' => $data['orderReference'],
        ];
    }

    /**
     * Verify a checkout transaction by order reference. Works in both
     * sandbox and production. Returns true only on a SUCCESS status.
     *
     * @throws NombaConnectionException
     */
    public function verifyOrderSucceeded(TeamProcessorConnection $connection, ApiKeyMode $mode, string $orderReference): bool
    {
        $body = $this->get($connection, $mode, '/v1/transactions/accounts/single', [
            'orderReference' => $orderReference,
        ]);

        return ($body['data']['status'] ?? null) === 'SUCCESS';
    }

    /**
     * Charge a previously tokenised card directly — no redirect, no card
     * details re-entered — the primitive that makes "automatically charge a
     * saved card" real rather than simulated (a subscription renewal or a
     * one-off transaction against a stored payment method).
     *
     * Nomba's own guidance: always verify the transaction afterwards via
     * {@see verifyOrderSucceeded()} before granting value, since the
     * synchronous response here isn't the final settlement authority.
     *
     * @param  array<string, mixed>  $order
     * @return array{approved: bool, message: string}
     *
     * @throws NombaConnectionException
     */
    public function chargeTokenizedCard(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, string $tokenKey): array
    {
        $body = $this->post($connection, $mode, '/v1/checkout/tokenized-card-payment', [
            'order' => $this->scopeOrderToSubaccount($order, $connection, $mode),
            'tokenKey' => $tokenKey,
        ]);

        return [
            'approved' => (bool) ($body['data']['status'] ?? false),
            'message' => (string) ($body['data']['message'] ?? 'Unknown response from Nomba.'),
        ];
    }

    /**
     * Reverse a (possibly partial) amount of a settled charge, by its order
     * reference. Nomba returns the refund's own transaction reference.
     *
     * @return array{success: bool, reference: string|null, message: string}
     *
     * @throws NombaConnectionException
     */
    public function refund(TeamProcessorConnection $connection, ApiKeyMode $mode, string $orderReference, int $amountMinor, string $currency): array
    {
        $body = $this->post($connection, $mode, '/v1/transactions/refund', [
            'orderReference' => $orderReference,
            'amount' => number_format($amountMinor / 100, 2, '.', ''),
            'currency' => $currency,
        ]);

        $data = $body['data'] ?? [];

        return [
            'success' => ($data['status'] ?? null) === 'SUCCESS' || ($data['status'] ?? false) === true,
            'reference' => isset($data['transactionRef']) ? (string) $data['transactionRef'] : null,
            'message' => (string) ($data['message'] ?? 'Refund processed.'),
        ];
    }

    /**
     * List a customer's tokenised cards by email — the synchronous fallback
     * for capturing a token when the webhook hasn't arrived yet.
     *
     * @return list<array<string, mixed>>
     *
     * @throws NombaConnectionException
     */
    public function listTokenizedCards(TeamProcessorConnection $connection, ApiKeyMode $mode, string $customerEmail): array
    {
        $body = $this->get($connection, $mode, '/v1/checkout/tokenized-card-data', [
            'customerEmail' => $customerEmail,
        ]);

        return $body['data']['tokenizedCardDataList'] ?? [];
    }

    /**
     * Delete a tokenised card on Nomba, so removing a payment method in
     * Bouclay also revokes the token on the processor.
     *
     * @throws NombaConnectionException
     */
    public function deleteTokenizedCard(TeamProcessorConnection $connection, ApiKeyMode $mode, string $tokenKey): void
    {
        $this->request($connection, $mode, 'delete', '/v1/checkout/tokenized-card-data', ['tokenKey' => $tokenKey]);
    }

    /**
     * Deposit funds into the team's subaccount when one is configured, by
     * setting `order.accountId` (the header stays the parent account). Applies
     * to any endpoint that carries an `order` object — checkout orders and
     * tokenised-card charges. A caller-supplied `accountId` is left untouched.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function scopeOrderToSubaccount(array $order, TeamProcessorConnection $connection, ApiKeyMode $mode): array
    {
        $subaccountId = $connection->credentialsFor($mode)['subaccountId'] ?? null;

        if ($subaccountId && ! isset($order['accountId'])) {
            $order['accountId'] = $subaccountId;
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws NombaConnectionException
     */
    private function post(TeamProcessorConnection $connection, ApiKeyMode $mode, string $path, array $payload): array
    {
        return $this->request($connection, $mode, 'post', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws NombaConnectionException
     */
    private function get(TeamProcessorConnection $connection, ApiKeyMode $mode, string $path, array $query): array
    {
        return $this->request($connection, $mode, 'get', $path, $query);
    }

    /**
     * Issue an authenticated request against Nomba on the team's account.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws NombaConnectionException
     */
    private function request(TeamProcessorConnection $connection, ApiKeyMode $mode, string $method, string $path, array $data): array
    {
        $credentials = $connection->credentialsFor($mode);

        if ($credentials === null) {
            throw NombaConnectionException::invalidCredentials('Nomba is not connected for this mode.');
        }

        $token = $this->client->accessToken(
            $mode,
            $credentials['accountId'],
            $credentials['clientId'],
            $credentials['clientSecret'],
        );

        try {
            // The accountId header is always the parent business account
            // (per Nomba docs); a subaccount, when used, is scoped via
            // `order.accountId` on the request body, not this header.
            $request = Http::baseUrl($this->baseUrlFor($mode))
                ->timeout(20)
                ->withToken($token)
                ->withHeaders(['accountId' => $credentials['accountId']]);

            $response = $method === 'get'
                ? $request->get($path, $data)
                : $request->{$method}($path, $data);
        } catch (\Throwable) {
            throw NombaConnectionException::unreachable();
        }

        $body = $response->json() ?? [];

        // Nomba signals success with a "00" code; anything else is an error.
        if (($body['code'] ?? null) !== '00') {
            throw NombaConnectionException::unknown($body['description'] ?? 'Nomba request failed.');
        }

        return $body;
    }

    private function baseUrlFor(ApiKeyMode $mode): string
    {
        return match ($mode) {
            ApiKeyMode::Test => config('services.nomba.sandbox_url', 'https://sandbox.nomba.com'),
            ApiKeyMode::Live => config('services.nomba.production_url', 'https://api.nomba.com'),
        };
    }
}
