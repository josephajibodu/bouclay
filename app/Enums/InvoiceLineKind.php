<?php

namespace App\Enums;

/**
 * What kind of charge an invoice line represents (schema.md §7).
 */
enum InvoiceLineKind: string
{
    case Subscription = 'subscription';
    case Proration = 'proration';
    case OneTime = 'one_time';
    case Tax = 'tax';
    case Discount = 'discount';
}
