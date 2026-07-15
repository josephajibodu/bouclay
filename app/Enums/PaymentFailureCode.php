<?php

namespace App\Enums;

/**
 * Why a charge failed, in Bouclay's words rather than any processor's — the
 * vocabulary every driver maps its own decline language onto, stored on
 * `payments.failure_code` (schema.md §8).
 *
 * The hard/soft split is the whole point. A soft decline is worth retrying:
 * the money wasn't there today and might be next week. A hard decline never
 * recovers — the card is closed, expired, or stolen — and retrying it burns
 * standing with the processor for a guaranteed 0% return, which is why
 * `subscriptions:process-dunning` skips the schedule and goes straight to the
 * terminal action.
 */
enum PaymentFailureCode: string
{
    case InsufficientFunds = 'insufficient_funds';
    case GenericDecline = 'generic_decline';
    case ProcessingError = 'processing_error';
    case CardExpired = 'card_expired';
    case InvalidCard = 'invalid_card';
    case StolenCard = 'stolen_card';
    case LostCard = 'lost_card';
    case Fraudulent = 'fraudulent';
    case TransactionNotPermitted = 'transaction_not_permitted';

    /**
     * Whether retrying this decline is pointless.
     */
    public function isHard(): bool
    {
        return match ($this) {
            self::CardExpired,
            self::InvalidCard,
            self::StolenCard,
            self::LostCard,
            self::Fraudulent,
            self::TransactionNotPermitted => true,

            self::InsufficientFunds,
            self::GenericDecline,
            self::ProcessingError => false,
        };
    }

    /**
     * Whether a stored code is a hard decline, tolerating a null or unknown
     * value from older rows — anything Bouclay can't recognise is treated as
     * soft, since refusing to retry on a guess is the costlier mistake.
     */
    public static function isHardCode(?string $code): bool
    {
        return self::tryFrom((string) $code)?->isHard() ?? false;
    }

    public function label(): string
    {
        return match ($this) {
            self::InsufficientFunds => 'Insufficient funds',
            self::GenericDecline => 'Declined',
            self::ProcessingError => 'Processing error',
            self::CardExpired => 'Card expired',
            self::InvalidCard => 'Invalid card',
            self::StolenCard => 'Stolen card',
            self::LostCard => 'Lost card',
            self::Fraudulent => 'Suspected fraud',
            self::TransactionNotPermitted => 'Transaction not permitted on this card',
        };
    }
}
