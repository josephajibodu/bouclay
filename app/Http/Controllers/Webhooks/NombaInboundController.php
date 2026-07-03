<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\TeamProcessorConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NombaInboundController extends Controller
{
    /**
     * Receive an inbound Nomba event (or the dashboard's synthetic test
     * event) and record that the endpoint is reachable.
     *
     * Signature verification and event-to-subscription mapping land in
     * Phase 7 — this just proves the URL is live.
     */
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $connection = TeamProcessorConnection::query()
            ->where('inbound_webhook_token', $token)
            ->first();

        abort_if(! $connection, 404);

        $connection->update(['webhook_verified_at' => now()]);

        return response()->json(['received' => true]);
    }
}
