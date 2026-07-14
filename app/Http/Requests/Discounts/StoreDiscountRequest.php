<?php

namespace App\Http\Requests\Discounts;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreDiscountRequest extends FormRequest
{
    /**
     * The team-scoped uniqueness rule for the discount code — overridden on
     * update to ignore the row being edited.
     */
    protected function codeUniqueRule(): Unique
    {
        return Rule::unique('discounts', 'code')
            ->where('team_id', $this->user()?->currentTeam?->id);
    }

    /**
     * A discount is a percentage OR a flat reduction (schema.md §7): a
     * percentage carries `percentage`, a flat carries `amount` + `currency`.
     * `duration_in_intervals` is required only for a `repeating` discount.
     * Amounts arrive in major units and are converted to minor in the
     * controller. Ownership of eligible plan/price ids is asserted there.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:255', $this->codeUniqueRule()],
            'type' => ['required', 'in:percentage,flat'],
            'percentage' => ['required_if:type,percentage', 'prohibited_if:type,flat', 'nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['required_if:type,flat', 'prohibited_if:type,percentage', 'nullable', 'numeric', 'min:0'],
            'currency' => ['required_if:type,flat', 'nullable', 'string', 'size:3'],
            'duration' => ['required', 'in:once,repeating,forever'],
            'duration_in_intervals' => ['required_if:duration,repeating', 'prohibited_unless:duration,repeating', 'nullable', 'integer', 'min:1'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'eligible_plan_ids' => ['nullable', 'array'],
            'eligible_plan_ids.*' => ['integer'],
            'eligible_price_ids' => ['nullable', 'array'],
            'eligible_price_ids.*' => ['integer'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
