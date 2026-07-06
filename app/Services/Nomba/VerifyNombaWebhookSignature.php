<?php

namespace App\Services\Nomba;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Verify inbound Nomba webhook HMAC signatures (Nomba docs: HmacSHA256,
 * base64-encoded, colon-delimited payload + nomba-timestamp).
 */
class VerifyNombaWebhookSignature
{
    public function isValid(Request $request, string $secret, int $maxAgeSeconds = 300): bool
    {
        $signature = $request->header('nomba-signature')
            ?? $request->header('nomba-sig-value');

        $timestamp = $request->header('nomba-timestamp');

        if (! is_string($signature) || $signature === '' || ! is_string($timestamp) || $timestamp === '') {
            return false;
        }

        if ($maxAgeSeconds > 0) {
            try {
                $eventTime = Carbon::parse($timestamp);
            } catch (\Throwable) {
                return false;
            }

            if ($eventTime->lt(now()->subSeconds($maxAgeSeconds))) {
                return false;
            }
        }

        $expected = $this->generate($request->all(), $secret, $timestamp);

        return hash_equals(strtolower($expected), strtolower($signature));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function generate(array $payload, string $secret, string $timestamp): string
    {
        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        /** @var array<string, mixed> $merchant */
        $merchant = is_array($data['merchant'] ?? null) ? $data['merchant'] : [];
        /** @var array<string, mixed> $transaction */
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];

        $responseCode = $transaction['responseCode'] ?? '';
        if ($responseCode === 'null') {
            $responseCode = '';
        }

        $hashingPayload = sprintf(
            '%s:%s:%s:%s:%s:%s:%s:%s:%s',
            (string) ($payload['event_type'] ?? ''),
            (string) ($payload['requestId'] ?? ''),
            (string) ($merchant['userId'] ?? ''),
            (string) ($merchant['walletId'] ?? ''),
            (string) ($transaction['transactionId'] ?? ''),
            (string) ($transaction['type'] ?? ''),
            (string) ($transaction['time'] ?? ''),
            (string) $responseCode,
            $timestamp,
        );

        return base64_encode(hash_hmac('sha256', $hashingPayload, $secret, true));
    }
}
