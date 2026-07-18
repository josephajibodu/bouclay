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
            // Exactly one of price_id / price_phases_id / schedule_steps per
            // line — enforced in CreateSubscription (the exact rule depends
            // on line position: only index 0 may carry a schedule).
            'items.*.price_id' => ['nullable', 'integer'],
            'items.*.price_phases_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.schedule_steps' => ['nullable', 'array', 'min:1'],
            'items.*.schedule_steps.*.price_id' => ['required_with:items.*.schedule_steps', 'integer'],
            'items.*.schedule_steps.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.schedule_steps.*.duration_interval' => ['nullable', 'in:day,week,month,year'],
            'items.*.schedule_steps.*.duration_count' => ['nullable', 'integer', 'min:1'],
            'items.*.end_behavior' => ['nullable', 'in:release,cancel'],
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
            'items.required' => 'Add a plan price to bill for.',
            'items.min' => 'Add a plan price to bill for.',
        ];
    }
}
