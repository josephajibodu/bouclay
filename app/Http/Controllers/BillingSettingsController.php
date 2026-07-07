<?php

namespace App\Http\Controllers;

use App\Support\DunningConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BillingSettingsController extends Controller
{
    /**
     * Show the current (fixed, not yet editable) dunning and proration
     * behavior for the team. Real per-team configuration is deferred —
     * see IMPLEMENTATION.md.
     */
    public function show(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewInvoices', $team);

        $dunning = DunningConfig::forTeam($team);

        return Inertia::render('billing-settings/show', [
            'dunning' => [
                'maxAttempts' => $dunning->maxAttempts,
                'retryIntervalsDays' => $dunning->retryIntervalsDays,
                'terminalAction' => $dunning->terminalAction->value,
                'incompleteGraceDays' => $dunning->incompleteGraceDays,
            ],
        ]);
    }
}
