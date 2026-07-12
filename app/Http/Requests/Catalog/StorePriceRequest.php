<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePriceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `plan_id` is required for recurring prices — only plan-bearing prices
     * can ever be subscribed to (schema.md §3); a one-time price is sold
     * directly off the product and carries no plan. Ownership (plan belongs
     * to this product) is asserted in the controller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['required_if:type,recurring', 'prohibited_if:type,one_time', 'nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:recurring,one_time'],
            'pricing_model' => ['required', 'in:standard,graduated'],
            'unit_amount' => ['required_unless:pricing_model,graduated', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'billing_interval' => ['required_if:type,recurring', 'in:day,week,month,year'],
            'billing_frequency' => ['nullable', 'integer', 'min:1'],
            'tiers' => ['required_if:pricing_model,graduated', 'array', 'min:1'],
            'tiers.*.up_to' => ['nullable', 'integer', 'min:1'],
            'tiers.*.unit_amount' => ['required', 'numeric', 'min:0'],
            'tiers.*.flat_amount' => ['nullable', 'numeric', 'min:0'],
            'trial_length' => ['nullable', 'integer', 'min:1', 'prohibited_if:type,one_time'],
            'trial_unit' => ['required_with:trial_length', 'nullable', 'in:day,week,month'],
            'trial_requires_payment_info' => ['nullable', 'boolean'],
            'trial_once_per_customer' => ['nullable', 'boolean'],
        ];
    }
}
