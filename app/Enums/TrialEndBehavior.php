<?php

namespace App\Enums;

/**
 * What happens when a free trial ends without a payment method on file — the
 * Stripe "missing_payment_method" fork (schema.md §4). Set per subscription.
 */
enum TrialEndBehavior: string
{
    case Cancel = 'cancel';
    case Pause = 'pause';
    case CreateInvoice = 'create_invoice';

    /**
     * A verb phrase used in trial-end warnings (SUBSCRIPTIONS_DESIGN §10.5).
     */
    public function verb(): string
    {
        return match ($this) {
            self::Cancel => 'cancel',
            self::Pause => 'pause',
            self::CreateInvoice => 'start billing',
        };
    }
}
