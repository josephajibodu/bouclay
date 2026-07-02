<?php

namespace App\Http\Requests\Teams;

use App\Concerns\BusinessDetailsValidationRules;
use App\Rules\TeamName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateTeamRequest extends FormRequest
{
    use BusinessDetailsValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new TeamName],
            ...$this->businessDetailsRules(),
        ];
    }
}
