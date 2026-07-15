<?php

namespace App\Services\Gateways\Flutterwave;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayCapabilities;
use App\Services\Gateways\GatewayConfigField;
use App\Services\Gateways\GatewayConfigFieldRole;
use App\Services\Gateways\GatewayConfigSchema;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayOrder;
use App\Services\Gateways\GatewayWebhookEvent;
use App\Services\Gateways\GatewayWebhookEventType;
use App\Services\Gateways\PaymentGateway;
use Illuminate\Http\Request;

/**
 * The Flutterwave driver (IMPLEMENTATION_V2 §V2-4b).
 *
 * Same tokenize-on-payment model as Nomba and Paystack, so no product flow
 * changes. What's Flutterwave's own stays here:
 *
 * - Money is major units on the wire as a number — a third format, after
 *   Nomba's major string and Paystack's minor int. {@see GatewayOrder} carries
 *   none of the three.
 * - **Transactions are addressed by Flutterwave's numeric id, not ours.**
 *   Bouclay only ever knows the `tx_ref` it generated, so verify goes through
 *   `verify_by_reference`, and a refund has to resolve our reference into
 *   their id before it can be issued. That round trip is the cost of the
 *   mismatch and it's paid here, where it belongs.
 * - Webhooks are verified by comparing a merchant-chosen `verif-hash` header
 *   against a stored value — not an HMAC of the body, unlike the other two.
 */
class FlutterwaveGateway implements PaymentGateway
{
    private const string GATEWAY = 'Flutterwave';

    public function __construct(private readonly FlutterwaveClient $client) {}

    public function processor(): PaymentProcessor
    {
        return PaymentProcessor::Flutterwave;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            currencies: ['NGN', 'USD', 'EUR', 'GBP', 'KES', 'GHS', 'ZAR', 'UGX', 'TZS'],
            refunds: true,
            partialRefunds: true,
            tokenization: true,
        );
    }

    public function configSchema(): GatewayConfigSchema
    {
        return new GatewayConfigSchema(
            label: 'Flutterwave',
            fields: [
                new GatewayConfigField(
                    key: 'secret_key',
                    label: 'Secret key',
                    secret: true,
                    help: 'From Flutterwave → Settings → API Keys.',
                    placeholder: 'FLWSECK_TEST-…',
                ),
                new GatewayConfigField(
                    key: 'public_key',
                    label: 'Public key',
                    required: false,
                    placeholder: 'FLWPUBK_TEST-…',
                ),
                new GatewayConfigField(
                    key: 'encryption_key',
                    label: 'Encryption key',
                    secret: true,
                    required: false,
                    help: 'Optional. Only needed if you collect card details directly rather than through Flutterwave’s hosted page.',
                ),
                new GatewayConfigField(
                    key: 'webhook_secret_hash',
                    label: 'Webhook secret hash',
                    secret: true,
                    role: GatewayConfigFieldRole::WebhookSecret,
                    help: 'The secret hash you set on Flutterwave → Settings → Webhooks. Bouclay rejects inbound events whose verif-hash doesn’t match it.',
                    rules: ['string', 'min:8', 'max:255'],
                ),
            ],
            docsUrl: 'https://developer.flutterwave.com/docs',
        );
    }

    public function verifyCredentials(ApiKeyMode $mode, array $credentials): void
    {
        $parsed = FlutterwaveCredentials::fromBlob($credentials);

        if ($parsed === null) {
            throw GatewayException::invalidCredentials(self::GATEWAY, 'a secret key is required');
        }

        if (! $parsed->matchesMode($mode)) {
            throw GatewayException::invalidCredentials(
                self::GATEWAY,
                'a '.$mode->value.' key must start with '.FlutterwaveCredentials::expectedPrefix($mode),
            );
        }

        // No dedicated credential-check endpoint; the cheapest authenticated
        // read proves the key is accepted.
        $this->client->get($parsed->secretKey, '/v3/transactions', ['page' => 1]);
    }

    public function createCheckout(TeamProcessorConnection $connection, ApiKeyMode $mode, GatewayOrder $order, bool $tokenizeCard = true): array
    {
        $payload = [
            'tx_ref' => $order->reference,
            // Major units, as a number — Flutterwave's third way of saying money.
            'amount' => $order->amountMinor / 100,
            'currency' => $order->currency,
            'customer' => ['email' => $order->customerEmail],
        ];

        if ($order->callbackUrl !== null) {
            $payload['redirect_url'] = $order->callbackUrl;
        }

        if ($order->cardOnly) {
            $payload['payment_options'] = 'card';
        }

        if ($order->customerReference !== null) {
            $payload['meta'] = ['bouclay_customer' => $order->customerReference];
        }

        $body = $this->client->post($this->secretKey($connection, $mode), '/v3/payments', $payload);
        $link = $body['data']['link'] ?? null;

        if (! is_string($link) || $link === '') {
            throw GatewayException::unknown(self::GATEWAY, 'Flutterwave did not return a checkout link.');
        }

        return [
            'checkoutLink' => $link,
            'orderReference' => $order->reference,
        ];
    }

    public function chargeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, GatewayOrder $order, string $tokenKey): array
    {
        $body = $this->client->post($this->secretKey($connection, $mode), '/v3/tokenized-charges', [
            'token' => $tokenKey,
            'email' => $order->customerEmail,
            'amount' => $order->amountMinor / 100,
            'currency' => $order->currency,
            'tx_ref' => $order->reference,
        ]);

        $data = $body['data'] ?? [];
        $approved = ($data['status'] ?? null) === 'successful';

        return [
            'approved' => $approved,
            'message' => (string) ($data['processor_response']
                ?? $body['message']
                ?? ($approved ? 'Approved.' : 'Declined by Flutterwave.')),
        ];
    }

    public function verifyCharge(TeamProcessorConnection $connection, ApiKeyMode $mode, string $reference): bool
    {
        try {
            $transaction = $this->transactionByReference($this->secretKey($connection, $mode), $reference);
        } catch (GatewayException) {
            // Flutterwave 404s an unknown reference as a business error; an
            // absent transaction plainly didn't settle.
            return false;
        }

        return ($transaction['status'] ?? null) === 'successful';
    }

    public function refund(TeamProcessorConnection $connection, ApiKeyMode $mode, string $chargeReference, int $amountMinor, string $currency): array
    {
        $secretKey = $this->secretKey($connection, $mode);

        // Refunds address Flutterwave's own transaction id, and Bouclay only
        // knows the tx_ref it generated — so trade a round trip for the id.
        $transaction = $this->transactionByReference($secretKey, $chargeReference);
        $id = $transaction['id'] ?? null;

        if ($id === null) {
            throw GatewayException::unknown(self::GATEWAY, 'that charge could not be found to refund.');
        }

        $body = $this->client->post($secretKey, '/v3/transactions/'.$id.'/refund', [
            'amount' => $amountMinor / 100,
        ]);

        $data = $body['data'] ?? [];
        $status = (string) ($data['status'] ?? '');

        // Flutterwave queues refunds; `pending` means accepted, not failed.
        $accepted = in_array($status, ['completed', 'pending'], true);

        return [
            'success' => $accepted,
            'reference' => isset($data['id']) ? (string) $data['id'] : null,
            'message' => $accepted ? 'Refund accepted by Flutterwave.' : 'Flutterwave declined the refund.',
        ];
    }

    public function resolveToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $customerEmail, string $orderReference): ?array
    {
        try {
            $transaction = $this->transactionByReference($this->secretKey($connection, $mode), $orderReference);
        } catch (GatewayException) {
            return null;
        }

        return $this->tokenFromTransaction($transaction);
    }

    public function revokeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $tokenKey): void
    {
        // Flutterwave exposes no endpoint to revoke a card token; it simply
        // stops being charged. Nothing to call, and a best-effort revoke must
        // not invent a failure.
    }

    public function verifyWebhookSignature(TeamProcessorConnection $connection, ApiKeyMode $mode, Request $request): bool
    {
        $expected = FlutterwaveCredentials::fromConnection($connection, $mode)?->webhookSecretHash;
        $given = $request->header('verif-hash');

        if ($expected === null || ! is_string($given) || $given === '') {
            return false;
        }

        // A plain shared secret rather than an HMAC — still compared in
        // constant time, since it's a secret being checked.
        return hash_equals($expected, $given);
    }

    public function parseWebhookEvent(array $payload): ?GatewayWebhookEvent
    {
        $event = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($event !== 'charge.completed') {
            return null;
        }

        $reference = $data['tx_ref'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        // One event covers both outcomes — the status inside decides which.
        $succeeded = ($data['status'] ?? null) === 'successful';

        return new GatewayWebhookEvent(
            type: $succeeded ? GatewayWebhookEventType::PaymentSucceeded : GatewayWebhookEventType::PaymentFailed,
            orderReference: $reference,
            token: $succeeded ? $this->tokenFromTransaction($data) : null,
            failureReason: $succeeded
                ? null
                : (string) ($data['processor_response'] ?? 'Payment failed.'),
            raw: $payload,
        );
    }

    public function identifiesConnection(TeamProcessorConnection $connection, array $payload): bool
    {
        // Flutterwave's payload carries no merchant account identifier, so a
        // tokenless ingress can't be resolved for it — the URL token is the
        // only route.
        return false;
    }

    /**
     * Look a transaction up by the reference Bouclay generated.
     *
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    private function transactionByReference(string $secretKey, string $reference): array
    {
        $body = $this->client->get($secretKey, '/v3/transactions/verify_by_reference', ['tx_ref' => $reference]);

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * The card token Flutterwave mints alongside a successful charge.
     *
     * @param  array<string, mixed>  $transaction
     * @return array{tokenKey: string, brand: string|null, last4: string|null, tokenExpiryMonth: string|null, tokenExpiryYear: string|null}|null
     */
    private function tokenFromTransaction(array $transaction): ?array
    {
        $card = is_array($transaction['card'] ?? null) ? $transaction['card'] : null;

        if ($card === null || empty($card['token'])) {
            return null;
        }

        return [
            'tokenKey' => (string) $card['token'],
            'brand' => isset($card['type']) ? (string) $card['type'] : null,
            'last4' => isset($card['last_4digits']) ? (string) $card['last_4digits'] : null,
            'tokenExpiryMonth' => isset($card['expiry']) ? $this->expiryPart((string) $card['expiry'], 0) : null,
            'tokenExpiryYear' => isset($card['expiry']) ? $this->expiryPart((string) $card['expiry'], 1) : null,
        ];
    }

    /**
     * Flutterwave reports expiry as one "MM/YY" string.
     */
    private function expiryPart(string $expiry, int $index): ?string
    {
        $parts = explode('/', $expiry);

        return isset($parts[$index]) && trim($parts[$index]) !== '' ? trim($parts[$index]) : null;
    }

    /**
     * @throws GatewayException
     */
    private function secretKey(TeamProcessorConnection $connection, ApiKeyMode $mode): string
    {
        $credentials = FlutterwaveCredentials::fromConnection($connection, $mode);

        if ($credentials === null) {
            throw GatewayException::invalidCredentials(self::GATEWAY, 'Flutterwave is not connected for this mode');
        }

        return $credentials->secretKey;
    }
}
