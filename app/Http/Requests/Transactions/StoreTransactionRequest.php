<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'collection_mode' => ['required', 'in:automatic,manual'],
            'payment_method_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.price_id' => ['nullable', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.unit_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Plain, human validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Choose a customer to bill.',
            'items.required' => 'Add at least one line item.',
            'items.min' => 'Add at least one line item.',
        ];
    }
}
