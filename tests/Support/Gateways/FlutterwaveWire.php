<?php

namespace Tests\Support\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Flutterwave's wire format: bearer secret key, `{status: 'success', data}`
 * envelopes (a string, not a bool), major-unit amounts as numbers, transactions
 * addressed by Flutterwave's own numeric id, and a webhook verified against a
 * plain shared hash rather than an HMAC.
 */
class FlutterwaveWire implements GatewayWire
{
    private const string HASH = 'whsec_flw_contract';

    private const int TRANSACTION_ID = 288200108;

    public function processor(): string
    {
        return 'flutterwave';
    }

    public function credentials(): array
    {
        return [
            'secret_key' => 'FLWSECK_TEST-contract',
            'public_key' => 'FLWPUBK_TEST-contract',
            'webhook_secret_hash' => self::HASH,
        ];
    }

    public function checkoutLink(): string
    {
        return 'https://checkout.flutterwave.com/v3/hosted/pay/contract';
    }

    public function fakeWire(
        bool $chargeApproved = true,
        bool $settled = true,
        bool $refundAccepted = true,
        string $declineReason = 'Insufficient funds',
        ?string $tokenKey = 'wire-token',
    ): void {
        $card = $tokenKey === null ? null : [
            'token' => $tokenKey,
            'type' => 'VISA',
            'last_4digits' => '4242',
            'expiry' => '11/31',
        ];

        Http::fake([
            '*/v3/payments' => Http::response([
                'status' => 'success',
                'data' => ['link' => $this->checkoutLink()],
            ]),
            '*/v3/tokenized-charges' => Http::response([
                'status' => 'success',
                'data' => array_filter([
                    'id' => self::TRANSACTION_ID,
                    'status' => $chargeApproved ? 'successful' : 'failed',
                    'processor_response' => $chargeApproved ? 'Approved' : $declineReason,
                    'card' => $card,
                ]),
            ]),
            // Verify and token resolution both go through reference lookup —
            // Bouclay never learns Flutterwave's id.
            '*/v3/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => array_filter([
                    'id' => self::TRANSACTION_ID,
                    'status' => $settled ? 'successful' : 'failed',
                    'processor_response' => $settled ? 'Approved' : $declineReason,
                    'card' => $card,
                ]),
            ]),
            // Refund addresses the numeric id the lookup above resolved.
            '*/v3/transactions/'.self::TRANSACTION_ID.'/refund' => Http::response([
                'status' => 'success',
                'data' => ['id' => 6789, 'status' => $refundAccepted ? 'completed' : 'failed'],
            ]),
            '*/v3/transactions*' => Http::response(['status' => 'success', 'data' => []]),
        ]);
    }

    public function signedWebhook(
        string $reference,
        bool $succeeded = true,
        ?string $tokenKey = 'wire-token',
        string $declineReason = 'Insufficient funds',
    ): Request {
        $payload = [
            // One event name for both outcomes; the status inside decides.
            'event' => 'charge.completed',
            'data' => array_filter([
                'id' => self::TRANSACTION_ID,
                'tx_ref' => $reference,
                'status' => $succeeded ? 'successful' : 'failed',
                'processor_response' => $succeeded ? 'Approved' : $declineReason,
                'card' => $succeeded && $tokenKey !== null ? [
                    'token' => $tokenKey,
                    'type' => 'VISA',
                    'last_4digits' => '4242',
                    'expiry' => '11/31',
                ] : null,
            ]),
        ];

        return Request::create(
            '/webhooks/flutterwave/token',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_VERIF_HASH' => self::HASH,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode($payload),
        );
    }
}
