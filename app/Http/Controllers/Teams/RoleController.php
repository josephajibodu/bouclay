<?php

namespace App\Http\Controllers\Teams;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\SaveRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /**
     * Show the roles page for the user's current team.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        Gate::authorize('viewAny', Role::class);

        $roles = $team->roles()
            ->withCount('memberships')
            ->with('permissions')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return Inertia::render('teams/roles', [
            'team' => [
                'id' => $team->id,
                'slug' => $team->slug,
            ],
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'isSystem' => $role->is_system,
                'memberCount' => $role->memberships_count,
                'permissionNames' => $role->permissions->pluck('name'),
            ]),
            'permissionCatalog' => collect(PermissionName::cases())
                ->map(fn (PermissionName $permission) => [
                    'name' => $permission->value,
                    'label' => $permission->label(),
                    'group' => $permission->group(),
                ])
                ->groupBy('group'),
            'permissions' => $user->toTeamPermissions($team),
        ]);
    }

    /**
     * Store a newly created role on the current team.
     */
    public function store(SaveRoleRequest $request): RedirectResponse
    {
        Gate::authorize('create', Role::class);

        $team = $request->user()->currentTeam;

        $role = $team->roles()->create(['name' => $request->validated('name')]);

        $role->permissions()->sync(
            Permission::query()->whereIn('name', $request->validated('permissions') ?? [])->pluck('id'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('roles.index');
    }

    /**
     * Update the specified role.
     */
    public function update(SaveRoleRequest $request, Role $role): RedirectResponse
    {
        abort_unless($role->team_id === $request->user()->currentTeam->id, 404);

        Gate::authorize('update', $role);

        $role->update(['name' => $request->validated('name')]);

        $role->permissions()->sync(
            Permission::query()->whereIn('name', $request->validated('permissions') ?? [])->pluck('id'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('roles.index');
    }

    /**
     * Delete the specified role.
     */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        abort_unless($role->team_id === $request->user()->currentTeam->id, 404);

        Gate::authorize('delete', $role);

        if ($role->memberships()->exists() || $role->invitations()->exists()) {
            return back()->withErrors([
                'role' => __('Reassign members and invitations before deleting this role.'),
            ]);
        }

        $role->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('roles.index');
    }
}
