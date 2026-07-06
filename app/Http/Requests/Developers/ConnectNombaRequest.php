<?php

namespace App\Http\Requests\Developers;

use App\Enums\ApiKeyMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectNombaRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(ApiKeyMode::class)],
            'account_id' => ['required', 'string', 'max:255'],
            'subaccount_id' => ['nullable', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
            'webhook_secret' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }
}
