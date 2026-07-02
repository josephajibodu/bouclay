<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    public function __construct(private SeedDefaultRoles $seedDefaultRoles)
    {
        //
    }

    /**
     * Create a new team, seed its default roles, and add the user as owner.
     *
     * @param  array<string, mixed>  $attributes  additional team attributes, e.g. business_type, website, country, line1, line2, city, postal_code
     */
    public function handle(User $user, string $name, bool $isPersonal = false, array $attributes = []): Team
    {
        return DB::transaction(function () use ($user, $name, $isPersonal, $attributes) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
                ...$attributes,
            ]);

            $adminRole = $this->seedDefaultRoles->handle($team);

            $team->memberships()->create([
                'user_id' => $user->id,
                'role_id' => $adminRole->id,
                'is_owner' => true,
            ]);

            $user->switchTeam($team);

            return $team;
        });
    }
}
