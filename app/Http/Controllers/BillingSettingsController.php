<?php

namespace App\Http\Controllers;

use App\Enums\DunningTerminalAction;
use App\Http\Requests\UpdateDunningSettingsRequest;
use App\Models\Team;
use App\Support\DunningConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BillingSettingsController extends Controller
{
    /**
     * Show the team's dunning and proration behavior.
     */
    public function show(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewInvoices', $team);

        return Inertia::render('billing-settings/show', [
            'dunning' => $this->dunningProps($team),
            'canManage' => $request->user()->toTeamPermissions($team)->canManageInvoices,
        ]);
    }

    /**
     * Update the team's retry schedule and terminal action
     * (IMPLEMENTATION_V2 §V2-4). Written to `team_settings.dunning_config` in
     * the schema.md shape, and read straight back by
     * `subscriptions:process-dunning` — there's no second copy to drift.
     */
    public function updateDunning(UpdateDunningSettingsRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageInvoices', $team);

        /** @var list<int> $intervals */
        $intervals = array_map(intval(...), $request->validated('retry_intervals_days'));

        $config = new DunningConfig(
            // One attempt per configured retry, plus the original charge.
            maxAttempts: count($intervals) + 1,
            retryIntervalsDays: $intervals,
            terminalAction: DunningTerminalAction::from($request->validated('terminal_action')),
            incompleteGraceDays: (int) $request->validated('incomplete_grace_days'),
        );

        $team->settings()->updateOrCreate([], ['dunning_config' => $config->toArray()]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Dunning settings saved.'),
        ]);

        return back();
    }

    /**
     * @return array{maxAttempts: int, retryIntervalsDays: list<int>, terminalAction: string, incompleteGraceDays: int}
     */
    private function dunningProps(Team $team): array
    {
        $dunning = DunningConfig::forTeam($team);

        return [
            'maxAttempts' => $dunning->maxAttempts,
            'retryIntervalsDays' => $dunning->retryIntervalsDays,
            'terminalAction' => $dunning->terminalAction->value,
            'incompleteGraceDays' => $dunning->incompleteGraceDays,
        ];
    }
}
