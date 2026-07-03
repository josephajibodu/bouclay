<?php

namespace App\Enums;

/**
 * MVP wires Relative only — Timestamp stays schema-present but unused
 * (see CATALOG_DESIGN.md §7.1 and IMPLEMENTATION.md Phase 3 "Defer").
 */
enum TrialDurationType: string
{
    case Relative = 'relative';
    case Timestamp = 'timestamp';
}
