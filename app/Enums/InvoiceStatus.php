<?php

namespace App\Enums;

/**
 * A frozen legal document's lifecycle (schema.md §7).
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
    case Uncollectible = 'uncollectible';

    /**
     * The plain-language badge label shown in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Void => 'Void',
            self::Uncollectible => 'Uncollectible',
        };
    }

    /**
     * The semantic colour token the frontend maps to a dot/badge, matching
     * the same palette as SubscriptionStatus (SUBSCRIPTIONS_DESIGN §9.4).
     */
    public function color(): string
    {
        return match ($this) {
            self::Paid => 'emerald',
            self::Open => 'amber',
            self::Uncollectible => 'red',
            self::Draft, self::Void => 'zinc',
        };
    }
}
