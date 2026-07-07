<?php

namespace App\Services\Invoicing;

/**
 * Map processor decline messages to Bouclay failure codes and hard/soft
 * classification — hard declines skip further retries (Phase 8).
 */
class ClassifyPaymentFailure
{
    public const string INSUFFICIENT_FUNDS = 'insufficient_funds';

    public const string GENERIC_DECLINE = 'generic_decline';

    public const string CARD_EXPIRED = 'card_expired';

    public const string INVALID_CARD = 'invalid_card';

    public const string STOLEN_CARD = 'stolen_card';

    public const string LOST_CARD = 'lost_card';

    public const string FRAUDULENT = 'fraudulent';

    public const string PROCESSING_ERROR = 'processing_error';

    /**
     * @return array{code: string, is_hard: bool}
     */
    public function classify(?string $reason): array
    {
        $normalized = mb_strtolower(trim((string) $reason));

        if ($normalized === '') {
            return ['code' => self::GENERIC_DECLINE, 'is_hard' => false];
        }

        if ($this->containsAny($normalized, ['insufficient fund', 'not sufficient fund', 'low balance'])) {
            return ['code' => self::INSUFFICIENT_FUNDS, 'is_hard' => false];
        }

        if ($this->containsAny($normalized, ['expired', 'expiry'])) {
            return ['code' => self::CARD_EXPIRED, 'is_hard' => true];
        }

        if ($this->containsAny($normalized, ['invalid card', 'invalid number', 'incorrect number'])) {
            return ['code' => self::INVALID_CARD, 'is_hard' => true];
        }

        if ($this->containsAny($normalized, ['stolen'])) {
            return ['code' => self::STOLEN_CARD, 'is_hard' => true];
        }

        if ($this->containsAny($normalized, ['lost card', 'lost/stolen'])) {
            return ['code' => self::LOST_CARD, 'is_hard' => true];
        }

        if ($this->containsAny($normalized, ['fraud', 'pick up card', 'do not honor'])) {
            return ['code' => self::FRAUDULENT, 'is_hard' => true];
        }

        if ($this->containsAny($normalized, ['timeout', 'unreachable', 'processing', 'try again'])) {
            return ['code' => self::PROCESSING_ERROR, 'is_hard' => false];
        }

        return ['code' => self::GENERIC_DECLINE, 'is_hard' => false];
    }

    public function isHardDecline(?string $failureCode): bool
    {
        return in_array($failureCode, [
            self::CARD_EXPIRED,
            self::INVALID_CARD,
            self::STOLEN_CARD,
            self::LOST_CARD,
            self::FRAUDULENT,
        ], true);
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
