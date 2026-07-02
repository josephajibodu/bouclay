<?php

namespace App\Rules;

use App\Models\TeamInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidTeamInvitationCode implements ValidationRule
{
    public function __construct(protected ?string $email)
    {
        //
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $invitation = TeamInvitation::where('code', $value)->first();

        if (! $invitation || ! $invitation->isPending()) {
            $fail(__('This invitation is no longer valid.'));

            return;
        }

        if (! $this->email || strtolower($invitation->email) !== strtolower($this->email)) {
            $fail(__('This invitation was sent to a different email address.'));
        }
    }
}
