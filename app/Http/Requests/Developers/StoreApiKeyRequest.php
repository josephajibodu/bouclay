<?php

namespace App\Http\Requests\Developers;

use App\Enums\ApiKeyKind;
use App\Enums\ApiKeyMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(ApiKeyKind::class)],
            'mode' => ['required', Rule::enum(ApiKeyMode::class)],
        ];
    }
}
