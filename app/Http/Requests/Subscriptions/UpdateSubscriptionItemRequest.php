<?php

namespace App\Http\Requests\Subscriptions;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_without_all:price_id,remove'],
            'price_id' => ['nullable', 'integer', 'required_without_all:quantity,remove'],
            // Mid-cycle policy (schema.md §6): omit to take the direction
            // default (increase → always, decrease → next_cycle).
            'proration_behavior' => ['nullable', 'string', 'in:always,none,next_cycle'],
            'remove' => ['nullable', 'boolean'],
        ];
    }
}
