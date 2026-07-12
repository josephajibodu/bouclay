<?php

namespace App\Http\Controllers\Developers;

use App\Enums\ApiKeyMode;
use App\Exceptions\Nomba\NombaConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Developers\ConnectNombaRequest;
use App\Models\TeamProcessorConnection;
use App\Services\Nomba\NombaClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NombaConnectionController extends Controller
{
    /**
     * Show the Nomba integration page for the current team.
     */
    public function show(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewIntegrations', $team);

        $connection = $team->processorConnection;

        return Inertia::render('developers/nomba', [
            'connection' => [
                'test' => $this->modeStatus($connection, ApiKeyMode::Test),
                'live' => $this->modeStatus($connection, ApiKeyMode::Live),
            ],
            'canManage' => $request->user()->toTeamPermissions($team)->canManageIntegrations,
        ]);
    }

    /**
     * Connect (or replace) credentials for one mode.
     */
    public function connect(ConnectNombaRequest $request, NombaClient $nomba): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageIntegrations', $team);

        $mode = ApiKeyMode::from($request->validated('mode'));
        $accountId = $request->validated('account_id');
        $subaccountId = $request->validated('subaccount_id') ?: null;
        $clientId = $request->validated('client_id');
        $clientSecret = $request->validated('client_secret');
        $webhookSecret = $request->validated('webhook_secret');

        try {
            // Authentication always uses the parent account, never the
            // subaccount — see TeamProcessorConnection::credentialsFor().
            $nomba->verifyCredentials($mode, $accountId, $clientId, $clientSecret);
        } catch (NombaConnectionException $exception) {
            throw ValidationException::withMessages([
                'client_secret' => $exception->getMessage(),
            ]);
        }

        // One row per gateway per team (schema.md §1); the first connected
        // gateway is the team's default for new checkouts.
        $connection = $team->processorConnections()->firstOrCreate(
            ['processor' => 'nomba'],
            ['is_default' => true],
        );

        // Replace the whole blob for this mode — a connect submits every
        // field, so stale keys never linger.
        $connection->forceFill([
            $mode === ApiKeyMode::Test ? 'test_credentials' : 'live_credentials' => array_filter([
                'account_id' => $accountId,
                'subaccount_id' => $subaccountId,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'webhook_secret' => $webhookSecret,
            ], fn (?string $value): bool => $value !== null && $value !== ''),
            $mode === ApiKeyMode::Test ? 'test_connected_at' : 'live_connected_at' => now(),
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $mode === ApiKeyMode::Test
                ? __('Nomba connected. You\'re in test mode.')
                : __('Live Nomba account connected.'),
        ]);

        return to_route('developers.nomba.show');
    }

    /**
     * Re-verify already-saved credentials for one mode.
     */
    public function test(Request $request, NombaClient $nomba): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageIntegrations', $team);

        $mode = ApiKeyMode::from($request->validate([
            'mode' => ['required', 'in:test,live'],
        ])['mode']);

        $connection = $team->processorConnection;
        $credentials = $connection?->credentialsFor($mode);

        abort_if(! $credentials, 404);

        try {
            $nomba->verifyCredentials($mode, $credentials['accountId'], $credentials['clientId'], $credentials['clientSecret']);
        } catch (NombaConnectionException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        }

        $connection->update(match ($mode) {
            ApiKeyMode::Test => ['test_connected_at' => now()],
            ApiKeyMode::Live => ['live_connected_at' => now()],
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection verified.')]);

        return back();
    }

    /**
     * Disconnect credentials for one mode.
     */
    public function disconnect(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageIntegrations', $team);

        $mode = ApiKeyMode::from($request->validate([
            'mode' => ['required', 'in:test,live'],
        ])['mode']);

        $connection = $team->processorConnection;

        abort_if(! $connection, 404);

        $connection->update(match ($mode) {
            ApiKeyMode::Test => [
                'test_credentials' => null,
                'test_connected_at' => null,
            ],
            ApiKeyMode::Live => [
                'live_credentials' => null,
                'live_connected_at' => null,
            ],
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Nomba disconnected.')]);

        return to_route('developers.nomba.show');
    }

    /**
     * @return array{connected: bool, connectedAt: string|null, accountIdPreview: string|null, subaccountIdPreview: string|null, clientIdPreview: string|null, webhookSecretSet: bool}
     */
    private function modeStatus(?TeamProcessorConnection $connection, ApiKeyMode $mode): array
    {
        $credentials = $connection?->credentialsFor($mode);

        return [
            'connected' => $connection?->isConnected($mode) ?? false,
            'connectedAt' => match ($mode) {
                ApiKeyMode::Test => $connection?->test_connected_at?->toISOString(),
                ApiKeyMode::Live => $connection?->live_connected_at?->toISOString(),
            },
            'accountIdPreview' => $credentials ? substr($credentials['accountId'], -4) : null,
            'subaccountIdPreview' => $credentials && $credentials['subaccountId'] ? substr($credentials['subaccountId'], -4) : null,
            'clientIdPreview' => $credentials ? substr($credentials['clientId'], -4) : null,
            'webhookSecretSet' => $connection?->hasWebhookSecret($mode) ?? false,
        ];
    }
}
