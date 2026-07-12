<?php

namespace App\Enums;

/**
 * Charge vs. credit note — `credit` is a seam for future credit notes;
 * every MVP invoice is a `charge` (schema.md §8).
 */
enum InvoiceType: string
{
    case Charge = 'charge';
    case Credit = 'credit';
}
