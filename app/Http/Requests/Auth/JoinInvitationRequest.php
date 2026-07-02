<?php

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class JoinInvitationRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * The invited email is never taken from user input — it comes from the
     * invitation record itself, so there's nothing to validate or trust here.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => $this->firstNameRules(),
            'last_name' => $this->lastNameRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
