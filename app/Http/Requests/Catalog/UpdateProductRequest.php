<?php

namespace App\Http\Requests\Catalog;

use App\Enums\CatalogStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'status' => ['sometimes', Rule::enum(CatalogStatus::class)],
            'custom_data' => ['sometimes', 'nullable', 'array'],
            'custom_data.*' => ['nullable', 'string', 'max:500'],
        ];
    }
}
