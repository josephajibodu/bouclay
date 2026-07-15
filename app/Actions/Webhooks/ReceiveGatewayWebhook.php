<?php

namespace App\Actions\Webhooks;

use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\GatewayModeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handle an inbound event from any payment gateway, once the caller has
 * resolved which team connection it belongs to (schema.md §9 — inbound; the
 * events Bouclay *emits* are a separate, outbound concern).
 *
 * The gateway-specific parts — is this signature genuine, what does this
 * payload mean — are both driver calls; everything after is shared.
 */
class ReceiveGatewayWebhook
{
    public function __construct(
        private readonly SettleGatewayPayment $settle,
        private readonly GatewayManager $gateways,
        private readonly GatewayModeResolver $modeResolver,
    ) {
        //
    }

    public function handle(TeamProcessorConnection $connection, Request $request): JsonResponse
    {
        $payload = $request->all();

        // Bouclay's own reachability self-check ("Send test event"), not a
        // processor event: same shape for every gateway, and unsigned by
        // definition since no processor sent it.
        if (($payload['event_type'] ?? null) === 'bouclay.test_event') {
            $connection->update(['webhook_verified_at' => now()]);

            return response()->json(['received' => true]);
        }

        if (! $this->gateways->has($connection->processor)) {
            return response()->json(['message' => 'No driver is registered for this gateway.'], 404);
        }

        $gateway = $this->gateways->forConnection($connection);
        $mode = $this->modeResolver->configuredMode();

        if (! $gateway->verifyWebhookSignature($connection, $mode, $request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $connection->update(['webhook_verified_at' => now()]);

        $event = $gateway->parseWebhookEvent($payload);

        // An event this driver doesn't map is acknowledged, not retried —
        // it's genuine, it just isn't ours to act on.
        if ($event !== null) {
            $this->settle->handle($connection, $event);
        }

        return response()->json(['received' => true]);
    }
}
