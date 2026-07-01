<?php

namespace App\Http\Controllers\Settings;

use App\Enums\BusinessType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\UpdateTeamRequest;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GeneralController extends Controller
{
    /**
     * Show the business settings page for the user's current team.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;

        return Inertia::render('settings/general', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
                'businessType' => $team->business_type?->value,
                'website' => $team->website,
                'country' => $team->country,
                'line1' => $team->line1,
                'line2' => $team->line2,
                'city' => $team->city,
                'postalCode' => $team->postal_code,
            ],
            'permissions' => $user->toTeamPermissions($team),
            'businessTypes' => BusinessType::options(),
        ]);
    }

    /**
     * Update the current team's business details.
     */
    public function update(UpdateTeamRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('update', $team);

        DB::transaction(function () use ($request, $team) {
            Team::whereKey($team->id)->lockForUpdate()->firstOrFail()->update($request->validated());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Business settings updated.')]);

        return to_route('general.edit');
    }
}
