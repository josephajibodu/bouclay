<?php

namespace App\Http\Controllers\Developers;

use App\Enums\ApiKeyMode;
use App\Http\Controllers\Controller;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayConfigSchema;
use App\Services\Gateways\GatewayException;
use App\Services\Gateways\GatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connect a team's payment gateway, per mode (IMPLEMENTATION_V2 §V2-4).
 *
 * Nothing here knows a gateway's fields: the form renders from the driver's
 * `configSchema()` manifest, saves validate against the same manifest, and the
 * credential blob is whatever the manifest declares. A new driver gets this
 * whole page for free — no controller change, no migration.
 */
class GatewayConnectionController extends Controller
{
    public function __construct(private readonly GatewayManager $gateways)
    {
        //
    }

    /**
     * Show the connection page for one gateway.
     */
    public function show(Request $request, string $processor): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewIntegrations', $team);

        $schema = $this->schemaFor($processor);
        $connection = $this->connectionFor($processor, $request);

        return Inertia::render('developers/gateway', [
            'processor' => $processor,
            'manifest' => $schema->toArray(),
            'capabilities' => [
                'currencies' => $this->gateways->driver($processor)->capabilities()->currencies,
                'refunds' => $this->gateways->driver($processor)->capabilities()->refunds,
            ],
            'connection' => [
                'test' => $this->modeStatus($schema, $connection, ApiKeyMode::Test),
                'live' => $this->modeStatus($schema, $connection, ApiKeyMode::Live),
            ],
            'canManage' => $request->user()->toTeamPermissions($team)->canManageIntegrations,
        ]);
    }

    /**
     * Connect (or replace) credentials for one mode.
     */
    public function connect(Request $request, string $processor): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageIntegrations', $team);

        $schema = $this->schemaFor($processor);
        $gateway = $this->gateways->driver($processor);

        $validated = $request->validate([
            'mode' => ['required', 'in:test,live'],
            ...$schema->validationRules(),
        ]);

        $mode = ApiKeyMode::from($validated['mode']);
        $credentials = $schema->credentialsFrom($validated);

        try {
            $gateway->verifyCredentials($mode, $credentials);
        } catch (GatewayException $exception) {
            // Blame the first secret field when the driver can't tell us which
            // value was wrong — it's the one a user most likely fat-fingered.
            throw ValidationException::withMessages([
                $this->blameField($schema) => $exception->getMessage(),
            ]);
        }

        // One row per gateway per team (schema.md §1); the first connected
        // gateway is the team's default for new checkouts.
        $connection = $team->processorConnections()->firstOrCreate(
            ['processor' => $processor],
            ['is_default' => ! $team->processorConnections()->where('is_default', true)->exists()],
        );

        // Replace the whole blob for this mode — a connect submits every
        // field, so stale keys never linger.
        $connection->forceFill([
            $mode === ApiKeyMode::Test ? 'test_credentials' : 'live_credentials' => $credentials,
            $mode === ApiKeyMode::Test ? 'test_connected_at' : 'live_connected_at' => now(),
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $mode === ApiKeyMode::Test
                ? __(':gateway connected. You\'re in test mode.', ['gateway' => $schema->label])
                : __('Live :gateway account connected.', ['gateway' => $schema->label]),
        ]);

        return to_route('developers.gateways.show', ['processor' => $processor]);
    }

    /**
     * Re-verify already-saved credentials for one mode.
     */
    public function test(Request $request, string $processor): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageIntegrations', $team);

        $mode = ApiKeyMode::from($request->validate([
            'mode' => ['required', 'in:test,live'],
        ])['mode']);

        $connection = $this->connectionFor($processor, $request);
        $credentials = $connection?->credentialBlobFor($mode);

        abort_if(! $connection || ! $credentials, 404);

        try {
            $this->gateways->driver($processor)->verifyCredentials($mode, $credentials);
        } catch (GatewayException $exception) {
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
    public function disconnect(Request $request, string $processor): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageIntegrations', $team);

        $mode = ApiKeyMode::from($request->validate([
            'mode' => ['required', 'in:test,live'],
        ])['mode']);

        $connection = $this->connectionFor($processor, $request);

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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':gateway disconnected.', ['gateway' => $this->schemaFor($processor)->label]),
        ]);

        return to_route('developers.gateways.show', ['processor' => $processor]);
    }

    private function schemaFor(string $processor): GatewayConfigSchema
    {
        abort_unless($this->gateways->has($processor), 404);

        return $this->gateways->driver($processor)->configSchema();
    }

    private function connectionFor(string $processor, Request $request): ?TeamProcessorConnection
    {
        return $request->user()->currentTeam
            ->processorConnections()
            ->where('processor', $processor)
            ->first();
    }

    /**
     * The field a credential rejection is attributed to.
     */
    private function blameField(GatewayConfigSchema $schema): string
    {
        foreach ($schema->fields as $field) {
            if ($field->secret) {
                return $field->key;
            }
        }

        return $schema->fields[0]->key;
    }

    /**
     * The per-mode state the page renders: whether it's connected, and a
     * last-four preview of each non-secret field. Secrets are never echoed
     * back — only whether one is set.
     *
     * @return array{connected: bool, connectedAt: string|null, fields: array<string, array{set: bool, preview: string|null}>}
     */
    private function modeStatus(GatewayConfigSchema $schema, ?TeamProcessorConnection $connection, ApiKeyMode $mode): array
    {
        $blob = $connection?->credentialBlobFor($mode) ?? [];
        $fields = [];

        foreach ($schema->fields as $field) {
            $value = $blob[$field->key] ?? null;
            $isSet = is_string($value) && $value !== '';

            $fields[$field->key] = [
                'set' => $isSet,
                'preview' => $isSet && ! $field->secret ? substr($value, -4) : null,
            ];
        }

        return [
            'connected' => $connection?->isConnected($mode) ?? false,
            'connectedAt' => match ($mode) {
                ApiKeyMode::Test => $connection?->test_connected_at?->toISOString(),
                ApiKeyMode::Live => $connection?->live_connected_at?->toISOString(),
            },
            'fields' => $fields,
        ];
    }
}
