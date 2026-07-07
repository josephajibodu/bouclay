<?php

namespace App\Http\Controllers\Developers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Developers\StoreOutboundWebhookEndpointRequest;
use App\Models\WebhookEndpoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class OutboundWebhookEndpointController extends Controller
{
    /**
     * Register a new outbound webhook endpoint for the current team.
     */
    public function store(StoreOutboundWebhookEndpointRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageWebhooks', $team);

        $generated = WebhookEndpoint::generateSigningSecret();

        $endpoint = $team->webhookEndpoints()->create([
            'url' => $request->validated('url'),
            'signing_secret' => $generated['secret'],
            'active' => true,
        ]);

        Inertia::flash('generatedWebhookSecret', [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'secret' => $generated['secret'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook endpoint added.')]);

        return to_route('developers.webhooks.show');
    }

    /**
     * Enable or disable an outbound webhook endpoint.
     */
    public function update(Request $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageWebhooks', $team);

        abort_unless($webhookEndpoint->team_id === $team->id, 404);

        $data = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $webhookEndpoint->update(['active' => $data['active']]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $data['active']
                ? __('Webhook endpoint enabled.')
                : __('Webhook endpoint disabled.'),
        ]);

        return to_route('developers.webhooks.show');
    }

    /**
     * Remove an outbound webhook endpoint.
     */
    public function destroy(Request $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageWebhooks', $team);

        abort_unless($webhookEndpoint->team_id === $team->id, 404);

        $webhookEndpoint->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook endpoint removed.')]);

        return to_route('developers.webhooks.show');
    }

    /**
     * Rotate the signing secret for an outbound webhook endpoint.
     */
    public function rotateSecret(Request $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageWebhooks', $team);

        abort_unless($webhookEndpoint->team_id === $team->id, 404);

        $generated = WebhookEndpoint::generateSigningSecret();

        $webhookEndpoint->update(['signing_secret' => $generated['secret']]);

        Inertia::flash('generatedWebhookSecret', [
            'id' => $webhookEndpoint->id,
            'url' => $webhookEndpoint->url,
            'secret' => $generated['secret'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signing secret rotated.')]);

        return to_route('developers.webhooks.show');
    }
}
