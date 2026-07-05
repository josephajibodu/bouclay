<?php

namespace App\Http\Requests\Customers;

use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $teamId = $this->user()->currentTeam->id;
        $customer = $this->route('customer');
        $customerId = $customer instanceof Customer ? $customer->id : null;

        return [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'external_ref' => [
                'nullable', 'string', 'max:255',
                Rule::unique('customers', 'external_ref')
                    ->where('team_id', $teamId)
                    ->whereNull('deleted_at')
                    ->ignore($customerId),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
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
