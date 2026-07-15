<?php

namespace App\Services\Gateways\Paystack;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentFailureCode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\CardNetworkDeclines;
use App\Services\Gateways\GatewayCapabilities;
use App\Services\Gateways\GatewayConfigField;
use App\Services\Gateways\GatewayConfigSchema;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayOrder;
use App\Services\Gateways\GatewayWebhookEvent;
use App\Services\Gateways\GatewayWebhookEventType;
use App\Services\Gateways\PaymentGateway;
use Illuminate\Http\Request;

/**
 * The Paystack driver (IMPLEMENTATION_V2 §V2-4b).
 *
 * Same tokenize-on-payment model as Nomba — a reusable card credential falls
 * out of a real hosted-checkout payment — so no product flow changes. What
 * differs is confined here:
 *
 * - Money is integer minor units on the wire, not a formatted major string.
 * - There is no separate webhook secret: inbound events are HMAC-SHA512 of the
 *   raw body under the secret key, so the manifest declares no such field and
 *   the webhooks page renders nothing to fill in.
 * - The token is an `authorization_code` returned with a successful charge —
 *   there's no "list this customer's cards" endpoint, so `resolveToken()` has
 *   to go back to the transaction.
 */
class PaystackGateway implements PaymentGateway
{
    private const string GATEWAY = 'Paystack';

    public function __construct(
        private readonly PaystackClient $client,
        private readonly CardNetworkDeclines $declines,
    ) {}

    public function processor(): PaymentProcessor
    {
        return PaymentProcessor::Paystack;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            currencies: ['NGN', 'GHS', 'ZAR', 'KES', 'USD'],
            refunds: true,
            partialRefunds: true,
            tokenization: true,
        );
    }

    public function configSchema(): GatewayConfigSchema
    {
        return new GatewayConfigSchema(
            label: 'Paystack',
            fields: [
                new GatewayConfigField(
                    key: 'secret_key',
                    label: 'Secret key',
                    secret: true,
                    help: 'From Paystack → Settings → API Keys. Bouclay also signs inbound webhooks with this — there is no separate webhook secret to set.',
                    placeholder: 'sk_test_…',
                ),
                new GatewayConfigField(
                    key: 'public_key',
                    label: 'Public key',
                    required: false,
                    help: 'Optional. Only needed if you render Paystack’s inline checkout yourself.',
                    placeholder: 'pk_test_…',
                ),
            ],
            docsUrl: 'https://paystack.com/docs/api',
        );
    }

    public function verifyCredentials(ApiKeyMode $mode, array $credentials): void
    {
        $parsed = PaystackCredentials::fromBlob($credentials);

        if ($parsed === null) {
            throw GatewayException::invalidCredentials(self::GATEWAY, 'a secret key is required');
        }

        // Catch a live key pasted into test (or the reverse) before it is ever
        // used — the API would happily accept it against the wrong data.
        if (! $parsed->matchesMode($mode)) {
            throw GatewayException::invalidCredentials(
                self::GATEWAY,
                'a '.$mode->value.' key must start with '.PaystackCredentials::expectedPrefix($mode),
            );
        }

        // No dedicated credential-check endpoint; listing one transaction is
        // the cheapest call that proves the key is accepted.
        $this->client->get($parsed->secretKey, '/transaction', ['perPage' => 1]);
    }

    public function createCheckout(TeamProcessorConnection $connection, ApiKeyMode $mode, GatewayOrder $order, bool $tokenizeCard = true): array
    {
        $payload = [
            'email' => $order->customerEmail,
            'amount' => $order->amountMinor,
            'currency' => $order->currency,
            'reference' => $order->reference,
        ];

        if ($order->callbackUrl !== null) {
            $payload['callback_url'] = $order->callbackUrl;
        }

        if ($order->cardOnly) {
            $payload['channels'] = ['card'];
        }

        if ($order->customerReference !== null) {
            $payload['metadata'] = ['bouclay_customer' => $order->customerReference];
        }

        $body = $this->client->post($this->secretKey($connection, $mode), '/transaction/initialize', $payload);
        $data = $body['data'] ?? [];

        if (! isset($data['authorization_url'])) {
            throw GatewayException::unknown(self::GATEWAY, 'Paystack did not return a checkout link.');
        }

        return [
            'checkoutLink' => (string) $data['authorization_url'],
            // Paystack echoes our reference back; trust ours regardless.
            'orderReference' => (string) ($data['reference'] ?? $order->reference),
        ];
    }

    public function chargeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, GatewayOrder $order, string $tokenKey): array
    {
        $body = $this->client->post($this->secretKey($connection, $mode), '/transaction/charge_authorization', [
            'authorization_code' => $tokenKey,
            'email' => $order->customerEmail,
            'amount' => $order->amountMinor,
            'currency' => $order->currency,
            'reference' => $order->reference,
        ]);

        $data = $body['data'] ?? [];
        $approved = ($data['status'] ?? null) === 'success';

        return [
            'approved' => $approved,
            // `gateway_response` is the card network's own words — the useful
            // half of a decline, and what the decline map reads.
            'message' => (string) ($data['gateway_response'] ?? ($approved ? 'Approved.' : 'Declined by Paystack.')),
        ];
    }

    public function verifyCharge(TeamProcessorConnection $connection, ApiKeyMode $mode, string $reference): bool
    {
        $body = $this->client->get($this->secretKey($connection, $mode), '/transaction/verify/'.rawurlencode($reference));

        return ($body['data']['status'] ?? null) === 'success';
    }

    public function refund(TeamProcessorConnection $connection, ApiKeyMode $mode, string $chargeReference, int $amountMinor, string $currency): array
    {
        $body = $this->client->post($this->secretKey($connection, $mode), '/refund', [
            'transaction' => $chargeReference,
            'amount' => $amountMinor,
        ]);

        $data = $body['data'] ?? [];

        // Paystack queues refunds: `pending` means accepted and on its way, so
        // it is not a failure. Settlement is confirmed by refund.processed.
        $status = (string) ($data['status'] ?? '');
        $accepted = in_array($status, ['processed', 'pending', 'processing'], true);

        return [
            'success' => $accepted,
            'reference' => isset($data['id']) ? (string) $data['id'] : null,
            'message' => $accepted ? 'Refund accepted by Paystack.' : 'Paystack declined the refund.',
        ];
    }

    public function resolveToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $customerEmail, string $orderReference): ?array
    {
        // Paystack has no "list this customer's cards" endpoint — the reusable
        // credential is minted with the charge, so the transaction is the only
        // place to find it.
        try {
            $body = $this->client->get($this->secretKey($connection, $mode), '/transaction/verify/'.rawurlencode($orderReference));
        } catch (GatewayException) {
            return null;
        }

        return $this->tokenFromTransaction($body['data'] ?? []);
    }

    public function revokeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $tokenKey): void
    {
        // Paystack has no deauthorize endpoint on the standard API; an
        // authorization simply stops being charged. Nothing to call, and a
        // best-effort revoke must not invent a failure.
    }

    public function verifyWebhookSignature(TeamProcessorConnection $connection, ApiKeyMode $mode, Request $request): bool
    {
        $credentials = PaystackCredentials::fromConnection($connection, $mode);
        $signature = $request->header('x-paystack-signature');

        if ($credentials === null || ! is_string($signature) || $signature === '') {
            return false;
        }

        // HMAC is over the raw body — re-encoding the parsed array would
        // change the bytes and never match.
        $expected = hash_hmac('sha512', $request->getContent(), $credentials->secretKey);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookEvent(array $payload): ?GatewayWebhookEvent
    {
        $event = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $type = match ($event) {
            'charge.success' => GatewayWebhookEventType::PaymentSucceeded,
            'charge.failed' => GatewayWebhookEventType::PaymentFailed,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        return new GatewayWebhookEvent(
            type: $type,
            orderReference: $reference,
            token: $type === GatewayWebhookEventType::PaymentSucceeded ? $this->tokenFromTransaction($data) : null,
            failureReason: $type === GatewayWebhookEventType::PaymentFailed
                ? (string) ($data['gateway_response'] ?? 'Payment failed.')
                : null,
            raw: $payload,
        );
    }

    public function classifyDecline(?string $reason): PaymentFailureCode
    {
        $normalized = mb_strtolower(trim((string) $reason));

        // Paystack's own phrasing for things the network says differently.
        // Everything else is the issuer's response passed through.
        return match (true) {
            str_contains($normalized, 'declined by financial institution') => PaymentFailureCode::GenericDecline,
            str_contains($normalized, 'invalid pin'), str_contains($normalized, 'incorrect pin') => PaymentFailureCode::TransactionNotPermitted,
            default => $this->declines->classify($reason),
        };
    }

    public function identifiesConnection(TeamProcessorConnection $connection, array $payload): bool
    {
        // Paystack's payload carries no merchant account id, so a tokenless
        // ingress can't be resolved for it — the URL token is the only route.
        return false;
    }

    /**
     * The reusable card credential Paystack mints alongside a successful
     * charge, if this transaction carries one.
     *
     * @param  array<string, mixed>  $data
     * @return array{tokenKey: string, brand: string|null, last4: string|null, tokenExpiryMonth: string|null, tokenExpiryYear: string|null}|null
     */
    private function tokenFromTransaction(array $data): ?array
    {
        $authorization = is_array($data['authorization'] ?? null) ? $data['authorization'] : null;

        if ($authorization === null || empty($authorization['authorization_code'])) {
            return null;
        }

        // Paystack flags authorizations it won't let you charge again; storing
        // one would mean a card that fails every renewal for no visible reason.
        if (($authorization['reusable'] ?? true) === false) {
            return null;
        }

        return [
            'tokenKey' => (string) $authorization['authorization_code'],
            'brand' => isset($authorization['brand']) ? (string) $authorization['brand'] : null,
            'last4' => isset($authorization['last4']) ? (string) $authorization['last4'] : null,
            'tokenExpiryMonth' => isset($authorization['exp_month']) ? (string) $authorization['exp_month'] : null,
            'tokenExpiryYear' => isset($authorization['exp_year']) ? (string) $authorization['exp_year'] : null,
        ];
    }

    /**
     * @throws GatewayException
     */
    private function secretKey(TeamProcessorConnection $connection, ApiKeyMode $mode): string
    {
        $credentials = PaystackCredentials::fromConnection($connection, $mode);

        if ($credentials === null) {
            throw GatewayException::invalidCredentials(self::GATEWAY, 'Paystack is not connected for this mode');
        }

        return $credentials->secretKey;
    }
}
