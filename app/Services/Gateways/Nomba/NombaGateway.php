<?php

namespace App\Services\Gateways\Nomba;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayCapabilities;
use App\Services\Gateways\GatewayConfigField;
use App\Services\Gateways\GatewayConfigFieldRole;
use App\Services\Gateways\GatewayConfigSchema;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayWebhookEvent;
use App\Services\Gateways\GatewayWebhookEventType;
use App\Services\Gateways\PaymentGateway;
use Illuminate\Http\Request;

/**
 * The Nomba driver — a thin adapter over the existing {@see NombaCheckout} and
 * {@see NombaClient} wrappers (a refactor, not a rewrite: behaviour is
 * identical, proven by the existing Nomba suites staying green). Nomba settles
 * NGN only and supports partial refunds and tokenization.
 *
 * Everything Nomba-shaped — credential keys, wire vocabulary, signature
 * scheme — is confined to this namespace.
 */
class NombaGateway implements PaymentGateway
{
    private const string GATEWAY = 'Nomba';

    public function __construct(
        private readonly NombaCheckout $checkout,
        private readonly NombaClient $client,
        private readonly ResolveNombaTokenizedCard $resolveTokenizedCard,
        private readonly VerifyNombaWebhookSignature $verifySignature,
    ) {}

    public function processor(): PaymentProcessor
    {
        return PaymentProcessor::Nomba;
    }

    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            currencies: ['NGN'],
            refunds: true,
            partialRefunds: true,
            tokenization: true,
        );
    }

    public function configSchema(): GatewayConfigSchema
    {
        return new GatewayConfigSchema(
            label: 'Nomba',
            fields: [
                new GatewayConfigField(
                    key: 'account_id',
                    label: 'Account ID',
                    help: 'Your parent business account — this is what authenticates, even when you also use a subaccount.',
                ),
                new GatewayConfigField(
                    key: 'subaccount_id',
                    label: 'Subaccount ID',
                    required: false,
                    help: 'Optional. When set, payments are deposited into this subaccount.',
                ),
                new GatewayConfigField(
                    key: 'client_id',
                    label: 'Client ID',
                ),
                new GatewayConfigField(
                    key: 'client_secret',
                    label: 'Client secret',
                    secret: true,
                ),
                new GatewayConfigField(
                    key: 'webhook_secret',
                    label: 'Webhook signing secret',
                    secret: true,
                    role: GatewayConfigFieldRole::WebhookSecret,
                    help: 'The signing key you set on Nomba’s dashboard. Bouclay rejects inbound events that don’t match it.',
                    rules: ['string', 'min:8', 'max:255'],
                ),
            ],
            docsUrl: 'https://docs.nomba.com',
        );
    }

    public function verifyCredentials(ApiKeyMode $mode, array $credentials): void
    {
        $parsed = NombaCredentials::fromBlob($credentials);

        if ($parsed === null) {
            throw GatewayException::invalidCredentials(self::GATEWAY, 'an account ID, client ID, and client secret are all required');
        }

        // Authentication always uses the parent account, never the subaccount.
        $this->client->verifyCredentials(
            $mode,
            $parsed->accountId,
            $parsed->clientId,
            $parsed->clientSecret,
        );
    }

    public function createCheckout(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, bool $tokenizeCard = true): array
    {
        return $this->checkout->createOrder($connection, $mode, $order, $tokenizeCard);
    }

    public function chargeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, array $order, string $tokenKey): array
    {
        return $this->checkout->chargeTokenizedCard($connection, $mode, $order, $tokenKey);
    }

    public function verifyCharge(TeamProcessorConnection $connection, ApiKeyMode $mode, string $reference): bool
    {
        return $this->checkout->verifyOrderSucceeded($connection, $mode, $reference);
    }

    public function refund(TeamProcessorConnection $connection, ApiKeyMode $mode, string $chargeReference, int $amountMinor, string $currency): array
    {
        return $this->checkout->refund($connection, $mode, $chargeReference, $amountMinor, $currency);
    }

    public function resolveToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $customerEmail, string $orderReference): ?array
    {
        // Nomba keys tokens by customer email, not by order — the order
        // reference has no part to play in the lookup.
        return $this->resolveTokenizedCard->handle($connection, $mode, $customerEmail);
    }

    public function revokeToken(TeamProcessorConnection $connection, ApiKeyMode $mode, string $tokenKey): void
    {
        $this->checkout->deleteTokenizedCard($connection, $mode, $tokenKey);
    }

    public function verifyWebhookSignature(TeamProcessorConnection $connection, ApiKeyMode $mode, Request $request): bool
    {
        $secret = NombaCredentials::fromConnection($connection, $mode)?->webhookSecret;

        // No secret saved means nothing can be trusted — an unsigned event is
        // indistinguishable from a forged one.
        if ($secret === null) {
            return false;
        }

        return $this->verifySignature->isValid($request, $secret);
    }

    public function parseWebhookEvent(array $payload): ?GatewayWebhookEvent
    {
        $type = match ((string) ($payload['event_type'] ?? '')) {
            'payment_success' => GatewayWebhookEventType::PaymentSucceeded,
            'payment_failed' => GatewayWebhookEventType::PaymentFailed,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $orderReference = $this->orderReference($payload);

        if ($orderReference === null) {
            return null;
        }

        return new GatewayWebhookEvent(
            type: $type,
            orderReference: $orderReference,
            token: $type === GatewayWebhookEventType::PaymentSucceeded ? $this->token($payload) : null,
            failureReason: $type === GatewayWebhookEventType::PaymentFailed ? $this->failureReason($payload) : null,
            raw: $payload,
        );
    }

    public function identifiesConnection(TeamProcessorConnection $connection, array $payload): bool
    {
        $accountId = $payload['data']['merchant']['userId']
            ?? $payload['data']['order']['accountId']
            ?? null;

        if (! is_string($accountId) || $accountId === '') {
            return false;
        }

        // Either environment's account may be the sender — the payload says
        // nothing about which mode it came from.
        foreach ([ApiKeyMode::Test, ApiKeyMode::Live] as $mode) {
            $credentials = NombaCredentials::fromConnection($connection, $mode);

            if ($credentials === null) {
                continue;
            }

            if ($credentials->accountId === $accountId || $credentials->subaccountId === $accountId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function orderReference(array $payload): ?string
    {
        $order = $payload['data']['order'] ?? null;

        if (! is_array($order)) {
            return null;
        }

        $orderReference = $order['orderReference'] ?? null;

        return is_string($orderReference) && $orderReference !== '' ? $orderReference : null;
    }

    /**
     * The card token Nomba mints alongside a paid order with
     * `tokenizeCard: true`, if this payload carries one.
     *
     * @param  array<string, mixed>  $payload
     * @return array{tokenKey: string, brand: string|null, last4: string|null, tokenExpiryMonth: int|string|null, tokenExpiryYear: int|string|null}|null
     */
    private function token(array $payload): ?array
    {
        $tokenized = $payload['data']['tokenizedCardData'] ?? null;
        $order = $payload['data']['order'] ?? null;

        if (! is_array($tokenized) || empty($tokenized['tokenKey'])) {
            return null;
        }

        return [
            'tokenKey' => (string) $tokenized['tokenKey'],
            'brand' => $tokenized['cardType'] ?? (is_array($order) ? ($order['cardType'] ?? null) : null),
            'last4' => is_array($order) ? ($order['cardLast4Digits'] ?? null) : null,
            'tokenExpiryMonth' => $tokenized['tokenExpiryMonth'] ?? null,
            'tokenExpiryYear' => $tokenized['tokenExpiryYear'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function failureReason(array $payload): string
    {
        $transaction = $payload['data']['transaction'] ?? null;

        if (is_array($transaction) && ! empty($transaction['responseMessage'])) {
            return (string) $transaction['responseMessage'];
        }

        return 'Payment failed.';
    }
}
