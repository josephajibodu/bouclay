<?php

namespace App\Enums;

/**
 * What kind of charge an invoice line represents (schema.md §8).
 *
 * `Discount` is presentation/adjustment only — a product discount is
 * recorded on the billable line's `discount_amount`, never as its own line
 * (the discount-representation invariant). `Credit` covers standalone
 * credits on future credit-note invoices.
 */
enum InvoiceLineKind: string
{
    case Plan = 'plan';
    case Addon = 'addon';
    case Proration = 'proration';
    case OneTime = 'one_time';
    case Tax = 'tax';
    case Discount = 'discount';
    case Credit = 'credit';
}
