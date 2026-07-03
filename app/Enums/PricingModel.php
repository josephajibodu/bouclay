<?php

namespace App\Enums;

/**
 * MVP wires Standard and Graduated only — Tiered/Volume/Package stay
 * schema-present but unused (see CATALOG_DESIGN.md §6.1 and §13).
 */
enum PricingModel: string
{
    case Standard = 'standard';
    case Tiered = 'tiered';
    case Volume = 'volume';
    case Graduated = 'graduated';
    case Package = 'package';

    /**
     * Determine whether this model prices off `price_tiers` rows.
     */
    public function usesTiers(): bool
    {
        return in_array($this, [self::Tiered, self::Volume, self::Graduated], true);
    }
}
