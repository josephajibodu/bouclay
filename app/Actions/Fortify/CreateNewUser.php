<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\BusinessDetailsValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Rules\TeamName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use BusinessDetailsValidationRules, ProfileValidationRules;

    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * Validate and create a newly registered user, along with the business
     * they're signing up on behalf of.
     *
     * Invited signups never reach this action — they're created by
     * JoinInvitationController, which joins an existing team instead.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $input = Validator::make($input, [
            ...$this->profileRules(),
            'password' => ['required', 'string', Password::default()],
            'business_name' => ['required', 'string', 'max:255', new TeamName],
            ...$this->businessDetailsRules(),
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->createTeam->handle($user, $input['business_name'], isPersonal: true, attributes: [
                'business_type' => $input['business_type'],
                'website' => $input['website'] ?? null,
                'country' => $input['country'],
                'line1' => $input['line1'],
                'line2' => $input['line2'] ?? null,
                'city' => $input['city'],
                'postal_code' => $input['postal_code'] ?? null,
            ]);

            return $user;
        });
    }
}
