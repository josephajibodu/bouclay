<?php

namespace App\Concerns;

use App\Enums\BusinessType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait BusinessDetailsValidationRules
{
    /**
     * Get the validation rules used to validate a team's business type and address details.
     *
     * The business/team name is validated separately by each consumer, since the
     * field name differs (`business_name` at signup, `name` when editing a team).
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function businessDetailsRules(): array
    {
        return [
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
