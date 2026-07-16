<?php

namespace Tests\Support\Gateways;

use App\Services\Gateways\Nomba\VerifyNombaWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Nomba's wire format: OAuth token issue, `code: '00'` envelopes, major-unit
 * amounts as strings, and a colon-delimited HMAC over selected payload fields.
 */
class NombaWire implements GatewayWire
{
    private const string SECRET = 'whsec_nomba_contract';

    public function processor(): string
    {
        return 'nomba';
    }

    public function credentials(): array
    {
        return [
            'account_id' => 'acct-contract',
            'client_id' => 'client-contract',
            'client_secret' => 'secret-contract',
            'webhook_secret' => self::SECRET,
        ];
    }

    public function checkoutLink(): string
    {
        return 'https://checkout.nomba.com/pay/contract';
    }

    public function fakeWire(
        bool $chargeApproved = true,
        bool $settled = true,
        bool $refundAccepted = true,
        string $declineReason = 'Insufficient funds',
        ?string $tokenKey = 'wire-token',
    ): void {
        Http::fake([
            // Every Nomba call authenticates first.
            '*/v1/auth/token/issue' => Http::response([
                'code' => '00',
                'data' => ['access_token' => 'at', 'refresh_token' => 'rt', 'expiresAt' => now()->addHour()->toISOString()],
            ]),
            '*/v1/checkout/order' => Http::response([
                'code' => '00',
                'data' => ['checkoutLink' => $this->checkoutLink(), 'orderReference' => 'wire-ref'],
            ]),
            // status is a BOOLEAN here (Nomba's docs: "status of the
            // transaction"), while the refund endpoint below reports a string.
            // The gateway is inconsistent with itself; the driver absorbs it.
            '*/v1/checkout/tokenized-card-payment' => Http::response([
                'code' => '00',
                'data' => [
                    'status' => $chargeApproved,
                    'message' => $chargeApproved ? 'Approved by Financial Insitution' : $declineReason,
                ],
            ]),
            '*/v1/transactions/accounts/single*' => Http::response([
                'code' => '00',
                'data' => ['status' => $settled ? 'SUCCESS' : 'FAILED'],
            ]),
            '*/v1/transactions/refund' => Http::response([
                'code' => '00',
                'data' => [
                    'status' => $refundAccepted ? 'SUCCESS' : 'FAILED',
                    'transactionRef' => 'REF-refund-1',
                    'message' => $refundAccepted ? 'Refund processed.' : 'Refund declined.',
                ],
            ]),
            // Nomba keys tokens by customer email and returns a LIST — the
            // order reference plays no part in its lookup.
            '*/v1/checkout/tokenized-card-data*' => Http::response([
                'code' => '00',
                'data' => [
                    'tokenizedCardDataList' => $tokenKey === null ? [] : [[
                        'tokenKey' => $tokenKey,
                        'customerEmail' => 'amina@example.test',
                        'cardType' => 'VISA',
                        'cardPan' => '539983******4242',
                        'tokenExpirationDate' => '11/31',
                    ]],
                ],
            ]),
        ]);
    }

    public function signedWebhook(
        string $reference,
        bool $succeeded = true,
        ?string $tokenKey = 'wire-token',
        string $declineReason = 'Insufficient funds',
    ): Request {
        $payload = [
            'event_type' => $succeeded ? 'payment_success' : 'payment_failed',
            'requestId' => 'req-'.$reference,
            'data' => [
                'merchant' => ['userId' => 'acct-contract', 'walletId' => 'wallet-1'],
                'transaction' => [
                    'transactionId' => 'txn-'.$reference,
                    'type' => 'online_checkout',
                    'time' => now()->toISOString(),
                    'responseCode' => $succeeded ? '00' : '51',
                    'responseMessage' => $succeeded ? 'Approved' : $declineReason,
                ],
                'order' => [
                    'orderReference' => $reference,
                    'cardLast4Digits' => '4242',
                    'cardType' => 'VISA',
                ],
            ],
        ];

        if ($succeeded && $tokenKey !== null) {
            $payload['data']['tokenizedCardData'] = [
                'tokenKey' => $tokenKey,
                'cardType' => 'VISA',
                'tokenExpiryMonth' => '11',
                'tokenExpiryYear' => '31',
            ];
        }

        $timestamp = now()->toISOString();
        $signature = (new VerifyNombaWebhookSignature)->generate($payload, self::SECRET, $timestamp);

        return Request::create(
            '/webhooks/nomba/token',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_NOMBA_SIGNATURE' => $signature,
                'HTTP_NOMBA_TIMESTAMP' => $timestamp,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode($payload),
        );
    }
}
