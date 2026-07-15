<?php

namespace App\Services\Gateways;

use App\Enums\PaymentFailureCode;

/**
 * The card networks' own decline language, mapped onto Bouclay's vocabulary.
 *
 * This is shared on purpose, and it isn't a hole in the driver boundary: the
 * words here belong to Visa and Mastercard, not to Nomba or Paystack or
 * Flutterwave. All three mostly hand the issuer's response through verbatim,
 * so triplicating "insufficient funds" across drivers would be copy-paste, not
 * encapsulation.
 *
 * A driver reaches for this *after* trying its own structured codes — see each
 * `classifyDecline()`. What's gateway-specific lives in the gateway; what's
 * the network's lives here.
 */
class CardNetworkDeclines
{
    /**
     * Needle → code. Order matters: the first match wins, so the more specific
     * phrases must come before the ones they contain.
     *
     * @var list<array{needles: list<string>, code: PaymentFailureCode}>
     */
    private const array RULES = [
        [
            'needles' => ['insufficient fund', 'not sufficient fund', 'low balance'],
            'code' => PaymentFailureCode::InsufficientFunds,
        ],
        [
            // "lost/stolen" reaches this before the bare "stolen" rule below.
            'needles' => ['lost card', 'lost/stolen'],
            'code' => PaymentFailureCode::LostCard,
        ],
        [
            'needles' => ['stolen'],
            'code' => PaymentFailureCode::StolenCard,
        ],
        [
            'needles' => ['expired', 'expiry'],
            'code' => PaymentFailureCode::CardExpired,
        ],
        [
            'needles' => ['invalid card', 'invalid number', 'incorrect number', 'no card record'],
            'code' => PaymentFailureCode::InvalidCard,
        ],
        [
            // Both spellings: issuers and gateways disagree, and Flutterwave
            // says "Do Not Honour" where Paystack says "Do not honor".
            'needles' => ['fraud', 'pick up card', 'do not honor', 'do not honour'],
            'code' => PaymentFailureCode::Fraudulent,
        ],
        [
            'needles' => ['not permitted', 'not allowed', 'restricted card', 'card is blocked'],
            'code' => PaymentFailureCode::TransactionNotPermitted,
        ],
        [
            'needles' => ['timeout', 'unreachable', 'processing', 'try again'],
            'code' => PaymentFailureCode::ProcessingError,
        ],
    ];

    /**
     * Classify an issuer's decline message. Anything unrecognised is a soft
     * generic decline: guessing "hard" would strand a payable subscription.
     */
    public function classify(?string $reason): PaymentFailureCode
    {
        $normalized = mb_strtolower(trim((string) $reason));

        if ($normalized === '') {
            return PaymentFailureCode::GenericDecline;
        }

        foreach (self::RULES as $rule) {
            foreach ($rule['needles'] as $needle) {
                if (str_contains($normalized, $needle)) {
                    return $rule['code'];
                }
            }
        }

        return PaymentFailureCode::GenericDecline;
    }
}
