<?php

namespace App\Http\Requests\Customers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $teamId = $this->user()->currentTeam->id;

        return [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'external_ref' => [
                'nullable', 'string', 'max:255',
                Rule::unique('customers', 'external_ref')
                    ->where('team_id', $teamId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors — plain and human, not
     * framework-speak (CUSTOMERS_DESIGN §16).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => "Add an email — it's where receipts go.",
            'email.email' => "That doesn't look like an email address.",
            'external_ref.unique' => 'You already use this reference for another customer.',
        ];
    }
}
