<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `sometimes` on every nested `price.*`/`trial.*` rule matters here:
     * without it, `required_unless`/`required_if` still evaluate — and
     * therefore still fail — even when the whole `price`/`trial` key is
     * absent from the request (a product created with no price at all).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],

            'price' => ['nullable', 'array'],
            'price.type' => ['sometimes', 'required_with:price', 'in:recurring,one_time'],
            'price.pricing_model' => ['sometimes', 'required_with:price', 'in:standard,graduated'],
            'price.unit_amount' => ['sometimes', 'required_unless:price.pricing_model,graduated', 'numeric', 'min:0'],
            'price.currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'price.billing_interval' => ['sometimes', 'required_if:price.type,recurring', 'in:day,week,month,year'],
            'price.billing_frequency' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'price.tiers' => ['sometimes', 'required_if:price.pricing_model,graduated', 'array', 'min:1'],
            'price.tiers.*.up_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'price.tiers.*.unit_amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'price.tiers.*.flat_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'trial' => ['nullable', 'array'],
            'trial.duration_amount' => ['sometimes', 'required_with:trial', 'integer', 'min:1'],
            'trial.duration_unit' => ['sometimes', 'required_with:trial', Rule::in(['day', 'week', 'month'])],
        ];
    }
}
