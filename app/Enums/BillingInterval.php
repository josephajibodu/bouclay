<?php

namespace App\Enums;

enum BillingInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /**
     * Get the display label for the interval, pluralised for a given frequency.
     */
    public function label(int $frequency = 1): string
    {
        $unit = match ($this) {
            self::Day => 'day',
            self::Week => 'week',
            self::Month => 'month',
            self::Year => 'year',
        };

        return $frequency === 1 ? $unit : "{$unit}s";
    }
}
