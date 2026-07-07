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
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_without:price_id'],
            'price_id' => ['nullable', 'integer', 'required_without:quantity'],
        ];
    }
}
