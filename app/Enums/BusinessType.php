<?php

namespace App\Enums;

enum BusinessType: string
{
    case Individual = 'individual';
    case Private = 'private';
    case Public = 'public';

    /**
     * Get the display label for the business type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Private => 'Private company',
            self::Public => 'Public company',
        };
    }

    /**
     * Get all business types as value/label options for select inputs.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()])
            ->values()
            ->toArray();
    }
}
