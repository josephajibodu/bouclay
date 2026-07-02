<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Http\Requests\Teams\RespondToTeamInvitationRequest;
use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation on the current team.
     */
    public function store(CreateTeamInvitationRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('inviteMember', $team);

        $invitation = $team->invitations()->create([
            'email' => $request->validated('email'),
            'role_id' => $request->validated('role_id'),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(3),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.members.index');
    }

    /**
     * Cancel the specified invitation on the current team.
     */
    public function destroy(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.members.index');
    }

    /**
     * Accept the invitation.
     */
    public function accept(RespondToTeamInvitationRequest $request, TeamInvitation $invitation, AcceptTeamInvitation $acceptTeamInvitation): RedirectResponse
    {
        $acceptTeamInvitation->handle($invitation, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

        return to_route('dashboard');
    }

    /**
     * Decline the invitation.
     */
    public function decline(RespondToTeamInvitationRequest $request, TeamInvitation $invitation): RedirectResponse
    {
        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('dashboard');
    }
}
