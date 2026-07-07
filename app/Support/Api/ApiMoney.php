<?php

namespace App\Support\Api;

/**
 * Convert stored minor-unit amounts for the public Billing API.
 *
 * Writes accept major units; responses must round-trip the same shape.
 */
final class ApiMoney
{
    public static function toMajorUnits(?int $minorAmount): ?float
    {
        if ($minorAmount === null) {
            return null;
        }

        return round($minorAmount / 100, 2);
    }
}
