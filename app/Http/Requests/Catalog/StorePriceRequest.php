<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePriceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
        ];
    }
}
