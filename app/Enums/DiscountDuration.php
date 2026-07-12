<?php

namespace App\Enums;

/**
 * How long a discount keeps applying to a subscription (schema.md §7).
 * Snapshotted onto `discount_redemptions.remaining_intervals` at redemption:
 * once → 1, repeating → duration_in_intervals, forever → null.
 */
enum DiscountDuration: string
{
    case Once = 'once';
    case Repeating = 'repeating';
    case Forever = 'forever';
}
