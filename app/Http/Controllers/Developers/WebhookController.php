<?php

namespace App\Http\Controllers\Developers;

use App\Enums\ApiKeyMode;
use App\Http\Controllers\Controller;
use App\Models\TeamProcessorConnection;
use App\Models\WebhookDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    /**
     * Show the inbound webhook page for the current team.
     */
    public function show(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewWebhooks', $team);

        $connection = $team->processorConnection;

        $endpoints = $team->webhookEndpoints()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($endpoint) => [
                'id' => $endpoint->id,
                'publicId' => $endpoint->public_id,
                'url' => $endpoint->url,
                'active' => $endpoint->active,
                'secretLastFour' => substr($endpoint->signing_secret, -4),
                'createdAt' => $endpoint->created_at?->toISOString(),
            ]);

        $deliveries = WebhookDelivery::query()
            ->whereHas('webhookEndpoint', fn ($query) => $query->where('team_id', $team->id))
            ->with(['event', 'webhookEndpoint'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (WebhookDelivery $delivery) => $delivery->toDashboardArray());

        return Inertia::render('developers/webhooks', [
            'endpoints' => $endpoints,
            'deliveries' => $deliveries,
            'connection' => $connection ? [
                'inboundUrl' => $this->inboundUrlFor($connection),
                'reachable' => $connection->webhook_verified_at !== null,
                'verifiedAt' => $connection->webhook_verified_at?->toISOString(),
                'testSecretSet' => $connection->hasWebhookSecret(ApiKeyMode::Test),
                'liveSecretSet' => $connection->hasWebhookSecret(ApiKeyMode::Live),
            ] : null,
            'canManage' => $request->user()->toTeamPermissions($team)->canManageWebhooks,
        ]);
    }

    /**
     * Save (or replace) the signing secret for one mode.
     *
     * The secret is chosen by the integrator on the gateway's dashboard, not
     * generated here — Bouclay just needs the same value to recompute the
     * signature on inbound events.
     */
    public function saveSecret(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageWebhooks', $team);

        $data = $request->validate([
            'mode' => ['required', Rule::enum(ApiKeyMode::class)],
            'secret' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $connection = $team->processorConnection;

        abort_if(! $connection, 404);

        $mode = ApiKeyMode::from($data['mode']);

        $connection->mergeCredentials($mode, ['webhook_secret' => $data['secret']]);
        $connection->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signing secret saved.')]);

        return to_route('developers.webhooks.show');
    }

    /**
     * Rotate the inbound endpoint, invalidating the old URL immediately.
     */
    public function rotate(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageWebhooks', $team);

        $connection = $team->processorConnection;

        abort_if(! $connection, 404);

        // inbound_webhook_token is intentionally excluded from Fillable —
        // it's only ever set here or by the model's creating() hook, never
        // from arbitrary mass-assigned input.
        $connection->inbound_webhook_token = Str::random(40);
        $connection->webhook_verified_at = null;
        $connection->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Webhook endpoint rotated. Update the URL on your gateway dashboard or events will stop arriving.'),
        ]);

        return to_route('developers.webhooks.show');
    }

    /**
     * Post a synthetic event through the team's own inbound URL to prove
     * it's reachable — not a real gateway sandbox trigger.
     */
    public function test(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageWebhooks', $team);

        $connection = $team->processorConnection;

        abort_if(! $connection, 404);

        $url = $this->inboundUrlFor($connection);
        $startedAt = microtime(true);

        try {
            $response = Http::timeout(5)->post($url, [
                'event_type' => 'bouclay.test_event',
                'requestId' => (string) Str::uuid(),
            ]);

            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($response->successful()) {
                Inertia::flash('toast', [
                    'type' => 'success',
                    'message' => __('Received and processed in :ms ms.', ['ms' => $elapsedMs]),
                ]);
            } else {
                Inertia::flash('toast', [
                    'type' => 'error',
                    'message' => __('Your endpoint responded with an error (:status).', ['status' => $response->status()]),
                ]);
            }
        } catch (\Throwable) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __("We couldn't reach your webhook URL just now. Try again in a moment."),
            ]);
        }

        return back();
    }

    /**
     * The URL this team's gateway posts events to. Built from the generalized
     * route, so it stays correct for whichever driver the connection is for.
     */
    private function inboundUrlFor(TeamProcessorConnection $connection): string
    {
        return URL::to(route('webhooks.gateway.receive', [
            'processor' => $connection->processor,
            'token' => $connection->inbound_webhook_token,
        ], absolute: false));
    }
}
