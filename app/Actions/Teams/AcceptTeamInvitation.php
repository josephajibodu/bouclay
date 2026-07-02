<?php

namespace App\Actions\Teams;

use App\Models\TeamInvitation;
use App\Models\User;

class AcceptTeamInvitation
{
    /**
     * Add the user to the invited team, mark the invitation accepted, and
     * switch their current team. Shared by every entry point that can end
     * in a user joining a team via invitation: the dashboard's pending
     * invitations modal, login-to-accept, and guest registration via a
     * join link.
     */
    public function handle(TeamInvitation $invitation, User $user): void
    {
        $invitation->team->memberships()->firstOrCreate(
            ['user_id' => $user->id],
            ['role_id' => $invitation->role_id],
        );

        $invitation->update(['accepted_at' => now()]);

        $user->switchTeam($invitation->team);
    }
}
