<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveTrialOfferRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trial_price_id' => ['required', 'integer', 'exists:prices,id'],
            'transition_to_different_product' => ['required', 'boolean'],
            'transition_product_id' => ['required_if:transition_to_different_product,true', 'nullable', 'integer', 'exists:products,id'],
            'transition_price_id' => ['required', 'integer', 'exists:prices,id', 'different:trial_price_id'],
            'duration_iterations' => ['required', 'integer', 'min:1'],
        ];
    }
}
