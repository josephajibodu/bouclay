<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamMemberRequest;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamMemberController extends Controller
{
    /**
     * Show the members page for the user's current team.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        Gate::authorize('viewMembers', $team);

        $memberships = $team->memberships()->with(['user', 'role'])->get();

        return Inertia::render('teams/members', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
            ],
            'members' => $memberships->map(fn (Membership $membership) => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'avatar' => $membership->user->avatar ?? null,
                'role_id' => $membership->role_id,
                'role_name' => $membership->role->name,
                'is_owner' => $membership->is_owner,
            ]),
            'invitations' => $team->invitations()
                ->whereNull('accepted_at')
                ->with('role')
                ->get()
                ->map(fn ($invitation) => [
                    'code' => $invitation->code,
                    'email' => $invitation->email,
                    'role_name' => $invitation->role->name,
                    'created_at' => $invitation->created_at->toISOString(),
                ]),
            'permissions' => $user->toTeamPermissions($team),
            'availableRoles' => $team->roles()->orderBy('name')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
            ]),
        ]);
    }

    /**
     * Update the specified team member's role on the current team.
     */
    public function update(UpdateTeamMemberRequest $request, User $user): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('updateMember', $team);

        $team->memberships()
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update(['role_id' => $request->validated('role_id')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member role updated.')]);

        return to_route('teams.members.index');
    }

    /**
     * Remove the specified member from the current team.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('removeMember', $team);

        abort_if($team->owner()?->is($user), 403, __('The team owner cannot be removed.'));

        $team->memberships()
            ->where('user_id', $user->id)
            ->delete();

        if ($user->isCurrentTeam($team)) {
            $user->switchTeam($user->personalTeam());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('teams.members.index');
    }
}
