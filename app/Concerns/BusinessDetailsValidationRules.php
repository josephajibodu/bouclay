<?php

namespace App\Concerns;

use App\Enums\BusinessType;
use App\Rules\TeamName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait BusinessDetailsValidationRules
{
    /**
     * Get the validation rules used to validate a new team's business and address details.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function businessDetailsRules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255', new TeamName],
            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'website' => ['nullable', 'url', 'max:255'],
            'country' => ['required', 'string', 'size:2'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ];
    }
}
