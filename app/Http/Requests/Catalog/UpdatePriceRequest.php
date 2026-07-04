<?php

namespace App\Http\Requests\Catalog;

use App\Enums\CatalogStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePriceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'in:recurring,one_time'],
            'pricing_model' => ['sometimes', 'in:standard,graduated'],
            'unit_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'billing_interval' => ['sometimes', 'nullable', 'in:day,week,month,year'],
            'billing_frequency' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::enum(CatalogStatus::class)],
            'tiers' => ['sometimes', 'array', 'min:1'],
            'tiers.*.up_to' => ['nullable', 'integer', 'min:1'],
            'tiers.*.unit_amount' => ['required_with:tiers', 'numeric', 'min:0'],
            'tiers.*.flat_amount' => ['nullable', 'numeric', 'min:0'],
            'custom_data' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
