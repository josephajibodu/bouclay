<?php

namespace App\Http\Requests\Subscriptions;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
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
            'trial_end_behavior' => ['nullable', 'in:cancel,pause,create_invoice'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.kind' => ['required', 'in:price,trial'],
            'items.*.price_id' => ['required_if:items.*.kind,price', 'integer'],
            'items.*.trial_offer_id' => ['required_if:items.*.kind,trial', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * Plain, human validation messages (SUBSCRIPTIONS_DESIGN §16.6).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Choose a customer to subscribe.',
            'items.required' => 'Add a product or a trial to bill for.',
            'items.min' => 'Add a product or a trial to bill for.',
        ];
    }
}
