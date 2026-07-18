<?php

namespace App\Enums;

/**
 * What happens once a subscription schedule reaches its terminal step
 * (schema.md §5) — the Stripe `end_behavior` problem: an implicit "forever"
 * isn't good enough once a schedule can end for real reasons.
 */
enum ScheduleEndBehavior: string
{
    case Release = 'release';
    case Cancel = 'cancel';
}
