<?php

namespace App\Enums;

/**
 * How a mid-cycle subscription item change is billed (schema.md §6, GAP-6).
 * A *request parameter*, never a stored column — the defaults are policy, the
 * value is the override.
 *
 * - `always`     — prorate the change and charge/credit the delta now (default
 *                  for increases).
 * - `none`       — apply the change immediately but bill nothing now; the next
 *                  cycle bills the new state (support / goodwill edits).
 * - `next_cycle` — defer the change to the next renewal via a scheduled `update`
 *                  row; nothing moves now (default for decreases / removals —
 *                  MVP has no customer credit balance to hold a mid-cycle
 *                  credit, GAP-3).
 */
enum ProrationBehavior: string
{
    case Always = 'always';
    case None = 'none';
    case NextCycle = 'next_cycle';
}
