<?php

namespace App\Http\Requests\Teams;

use App\Rules\UniqueTeamInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTeamInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->user()->currentTeam)],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('team_id', $this->user()->currentTeam->id),
            ],
        ];
    }
}
