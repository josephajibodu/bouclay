<?php

namespace App\Enums;

/**
 * The unit of a simple trial's length on a price (schema.md §3).
 */
enum TrialUnit: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
