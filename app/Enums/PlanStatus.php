<?php

namespace App\Enums;

/**
 * Plan lifecycle (schema.md §3). Unlike products/prices, plans have a `draft`
 * state: a draft (or archived) plan's prices are not purchasable regardless
 * of the price's own status — enforced at the application layer.
 */
enum PlanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
