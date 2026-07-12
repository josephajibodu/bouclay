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
     * The same payload drives both write paths: an in-place update while
     * the price is unreferenced, or ReplacePrice (successor + archive) once
     * it has subscribers — the controller decides, the form doesn't change.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plan_id' => ['sometimes', 'nullable', 'integer'],
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
            'trial_length' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'trial_unit' => ['sometimes', 'nullable', 'in:day,week,month'],
            'trial_requires_payment_info' => ['sometimes', 'boolean'],
            'trial_once_per_customer' => ['sometimes', 'boolean'],
            'custom_data' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
