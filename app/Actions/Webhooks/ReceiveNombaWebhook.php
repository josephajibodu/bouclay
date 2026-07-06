<?php

namespace App\Actions\Webhooks;

use App\Models\TeamProcessorConnection;
use App\Services\Nomba\NombaModeResolver;
use App\Services\Nomba\VerifyNombaWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Verify and process an inbound Nomba webhook once the team's connection
 * has already been resolved by the caller.
 */
class ReceiveNombaWebhook
{
    public function __construct(
        private readonly ProcessNombaInboundWebhook $process,
        private readonly VerifyNombaWebhookSignature $verifySignature,
        private readonly NombaModeResolver $modeResolver,
    ) {
        //
    }

    public function handle(TeamProcessorConnection $connection, Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventType = (string) ($payload['event_type'] ?? '');

        if ($eventType === 'bouclay.test_event') {
            $connection->update(['webhook_verified_at' => now()]);

            return response()->json(['received' => true]);
        }

        $mode = $this->modeResolver->configuredMode();
        $secret = $connection->webhookSecretFor($mode);

        if (! is_string($secret) || $secret === '') {
            return response()->json(['message' => 'Webhook signing secret is not configured.'], 401);
        }

        if (! $this->verifySignature->isValid($request, $secret)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $connection->update(['webhook_verified_at' => now()]);

        $this->process->handle($connection, $payload);

        return response()->json(['received' => true]);
    }
}
