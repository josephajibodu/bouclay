<?php

namespace App\Actions\PaymentLinks;

use App\Models\Subscription;

/**
 * What a payment-link submission resolved to. A link either sends the customer
 * to a gateway to pay, or — when its price starts a free trial that needs no
 * card (schema.md §5) — starts the subscription outright with nothing to
 * collect today.
 *
 * The two are modelled explicitly because the difference is money: returning a
 * bare URL left the free-trial case with nowhere to go but the charge path,
 * which is how trials came to bill the full price on day 0.
 */
readonly class PaymentLinkCheckoutResult
{
    private function __construct(
        public ?string $checkoutLink,
        public ?Subscription $subscription,
    ) {}

    /**
     * The customer owes money now — send them to the gateway.
     */
    public static function redirect(string $checkoutLink): self
    {
        return new self($checkoutLink, null);
    }

    /**
     * The trial started; nothing is due today and there is no gateway leg.
     */
    public static function trialStarted(Subscription $subscription): self
    {
        return new self(null, $subscription);
    }

    public function needsPayment(): bool
    {
        return $this->checkoutLink !== null;
    }
}
