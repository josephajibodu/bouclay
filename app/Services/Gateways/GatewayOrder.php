<?php

namespace App\Services\Gateways;

/**
 * What Bouclay is asking a gateway to collect — stated in Bouclay's terms, not
 * any processor's (IMPLEMENTATION_V2 §V2-4b).
 *
 * This exists because the previous `array{orderReference, amount, …}` shape
 * called itself processor-agnostic while being Nomba's wire format in
 * disguise: `amount` was a major-unit string because Nomba wants
 * `"5000.00"`, and `allowedPaymentMethods: ['Card']` was Nomba's vocabulary.
 * Every call site was formatting for a gateway it shouldn't know about, and a
 * driver that wants integer minor units (Paystack) or a different channel word
 * had nowhere to stand.
 *
 * So: money is minor units, like everywhere else in the schema, and intent is
 * stated as intent. Translating either into a processor's wire format is the
 * driver's job.
 */
readonly class GatewayOrder
{
    /**
     * @param  string  $reference  Bouclay's idempotent id for this attempt —
     *                             what the gateway echoes back on its webhook
     * @param  int  $amountMinor  minor units (kobo/cents), Bouclay's canonical money unit
     * @param  string|null  $customerReference  the customer's public id, when the gateway can carry one
     * @param  bool  $cardOnly  restrict to cards — the intent behind tokenization,
     *                          since a transfer or USSD payment mints no reusable token
     */
    public function __construct(
        public string $reference,
        public string $customerEmail,
        public int $amountMinor,
        public string $currency,
        public ?string $customerReference = null,
        public ?string $callbackUrl = null,
        public bool $cardOnly = false,
    ) {}

    /**
     * The amount as a fixed-2 major-unit string, for gateways that price in
     * major units on the wire.
     */
    public function amountMajor(): string
    {
        return number_format($this->amountMinor / 100, 2, '.', '');
    }
}
