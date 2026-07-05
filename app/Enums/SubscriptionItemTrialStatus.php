<?php

namespace App\Enums;

/**
 * The lifecycle of an applied trial on one subscription item (schema.md §5).
 */
enum SubscriptionItemTrialStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Canceled = 'canceled';
    case Expired = 'expired';
}
