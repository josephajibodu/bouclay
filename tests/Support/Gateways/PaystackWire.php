<?php

namespace Tests\Support\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Paystack's wire format: bearer secret key, `{status: true, data}` envelopes,
 * integer minor-unit amounts, and an HMAC-SHA512 of the raw body under the
 * secret key — no separate webhook secret at all.
 */
class PaystackWire implements GatewayWire
{
    private const string SECRET_KEY = 'sk_test_contract';

    public function processor(): string
    {
        return 'paystack';
    }

    public function credentials(): array
    {
        return ['secret_key' => self::SECRET_KEY, 'public_key' => 'pk_test_contract'];
    }

    public function checkoutLink(): string
    {
        return 'https://checkout.paystack.com/contract';
    }

    public function fakeWire(
        bool $chargeApproved = true,
        bool $settled = true,
        bool $refundAccepted = true,
        string $declineReason = 'Insufficient funds',
        ?string $tokenKey = 'wire-token',
    ): void {
        $authorization = $tokenKey === null ? null : [
            'authorization_code' => $tokenKey,
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => '11',
            'exp_year' => '2031',
            'reusable' => true,
        ];

        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => $this->checkoutLink(), 'reference' => 'wire-ref'],
            ]),
            '*/transaction/charge_authorization' => Http::response([
                'status' => true,
                'data' => array_filter([
                    'status' => $chargeApproved ? 'success' : 'failed',
                    'gateway_response' => $chargeApproved ? 'Approved' : $declineReason,
                    'authorization' => $authorization,
                ]),
            ]),
            // Verify and token resolution share one endpoint: Paystack has no
            // card-list API, so the token is read back off the transaction.
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => array_filter([
                    'status' => $settled ? 'success' : 'failed',
                    'gateway_response' => $settled ? 'Approved' : $declineReason,
                    'authorization' => $authorization,
                ]),
            ]),
            '*/refund' => Http::response([
                'status' => $refundAccepted,
                'data' => $refundAccepted ? ['id' => 4321, 'status' => 'processed'] : [],
                'message' => $refundAccepted ? 'Refund queued' : 'Refund declined',
            ]),
            '*/transaction*' => Http::response(['status' => true, 'data' => []]),
        ]);
    }

    public function signedWebhook(
        string $reference,
        bool $succeeded = true,
        ?string $tokenKey = 'wire-token',
        string $declineReason = 'Insufficient funds',
    ): Request {
        $payload = [
            'event' => $succeeded ? 'charge.success' : 'charge.failed',
            'data' => array_filter([
                'reference' => $reference,
                'status' => $succeeded ? 'success' : 'failed',
                'gateway_response' => $succeeded ? 'Approved' : $declineReason,
                'authorization' => $succeeded && $tokenKey !== null ? [
                    'authorization_code' => $tokenKey,
                    'brand' => 'visa',
                    'last4' => '4242',
                    'exp_month' => '11',
                    'exp_year' => '2031',
                    'reusable' => true,
                ] : null,
            ]),
        ];

        $body = json_encode($payload);

        return Request::create(
            '/webhooks/paystack/token',
            'POST',
            [],
            [],
            [],
            [
                // Over the raw bytes, which is why the body is passed through
                // verbatim rather than re-encoded.
                'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET_KEY),
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        );
    }
}
