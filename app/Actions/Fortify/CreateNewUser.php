<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\BusinessDetailsValidationRules;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\TeamName;
use App\Rules\ValidTeamInvitationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use BusinessDetailsValidationRules, PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * Registering through a valid invitation link joins the inviting team
     * directly instead of creating a business for the new user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $invitationCode = $input['invitation'] ?? null;

        $rules = [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ];

        if ($invitationCode) {
            $rules['invitation'] = ['required', 'string', new ValidTeamInvitationCode($input['email'] ?? null)];
        } else {
            $rules['business_name'] = ['required', 'string', 'max:255', new TeamName];
            $rules = [...$rules, ...$this->businessDetailsRules()];
        }

        $input = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($input, $invitationCode) {
            $user = User::create([
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            if ($invitationCode) {
                $this->joinInvitedTeam($invitationCode, $user);
            } else {
                $this->createTeam->handle($user, $input['business_name'], isPersonal: true, attributes: [
                    'business_type' => $input['business_type'],
                    'website' => $input['website'] ?? null,
                    'country' => $input['country'],
                    'line1' => $input['line1'],
                    'line2' => $input['line2'] ?? null,
                    'city' => $input['city'],
                    'postal_code' => $input['postal_code'] ?? null,
                ]);
            }

            return $user;
        });
    }

    /**
     * Accept the invitation and add the new user to the inviting team,
     * instead of creating a business for them.
     *
     * Re-checks the invitation inside the transaction so a race with
     * cancellation rolls the whole registration back rather than leaving
     * the user without a team.
     */
    private function joinInvitedTeam(string $invitationCode, User $user): void
    {
        $invitation = TeamInvitation::where('code', $invitationCode)->lockForUpdate()->first();

        if (! $invitation || ! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation is no longer valid.'),
            ]);
        }

        $invitation->team->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            ['role_id' => $invitation->role_id],
        );

        $invitation->update(['accepted_at' => now()]);

        $user->switchTeam($invitation->team);
    }
}
