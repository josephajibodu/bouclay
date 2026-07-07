<?php

namespace App\Services\Webhooks;

/**
 * Sign outbound webhook payloads for integrator verification.
 */
class SignOutboundWebhook
{
    /**
     * Build the Bouclay-Signature header value for a payload.
     */
    public function header(string $secret, string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.$this->signature($secret, $payload, $timestamp);
    }

    /**
     * Verify a Bouclay-Signature header against a payload.
     */
    public function verify(string $secret, string $payload, string $header, int $maxAgeSeconds = 300): bool
    {
        $parts = $this->parseHeader($header);

        if ($parts === null) {
            return false;
        }

        if ($maxAgeSeconds > 0 && (time() - $parts['timestamp']) > $maxAgeSeconds) {
            return false;
        }

        $expected = $this->signature($secret, $payload, $parts['timestamp']);

        return hash_equals($expected, $parts['signature']);
    }

    private function signature(string $secret, string $payload, int $timestamp): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    /**
     * @return array{timestamp: int, signature: string}|null
     */
    private function parseHeader(string $header): ?array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && is_numeric($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1' && is_string($value) && $value !== '') {
                $signature = $value;
            }
        }

        if ($timestamp === null || $signature === null) {
            return null;
        }

        return [
            'timestamp' => $timestamp,
            'signature' => $signature,
        ];
    }
}
