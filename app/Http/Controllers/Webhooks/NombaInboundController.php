<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\TeamProcessorConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NombaInboundController extends Controller
{
    /**
     * Receive an inbound Nomba event (or the dashboard's synthetic test
     * event) and record that the endpoint is reachable.
     *
     * Full signature verification and event-to-subscription mapping land in
     * Phase 7. For Phase 4 we do one small thing beyond marking the URL live:
     * stash any tokenised-card payload keyed by its order reference, so the
     * checkout callback can correlate order → token exactly (the only place
     * Nomba ties them together — CUSTOMERS_DESIGN §10.3, §14.8).
     */
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $connection = TeamProcessorConnection::query()
            ->where('inbound_webhook_token', $token)
            ->first();

        abort_if(! $connection, 404);

        $connection->update(['webhook_verified_at' => now()]);

        $this->stashTokenizedCard($request);

        return response()->json(['received' => true]);
    }

    /**
     * Cache the tokenised-card data from a `payment_success` event so the
     * checkout callback can pick it up by order reference.
     */
    private function stashTokenizedCard(Request $request): void
    {
        if ($request->input('event_type') !== 'payment_success') {
            return;
        }

        $tokenized = $request->input('data.tokenizedCardData');
        $order = $request->input('data.order');
        $orderReference = $order['orderReference'] ?? null;

        if (! is_array($tokenized) || empty($tokenized['tokenKey']) || ! $orderReference) {
            return;
        }

        Cache::put("nomba_token:{$orderReference}", [
            'tokenKey' => $tokenized['tokenKey'],
            'brand' => $tokenized['cardType'] ?? ($order['cardType'] ?? null),
            'last4' => $order['cardLast4Digits'] ?? null,
            'tokenExpiryMonth' => $tokenized['tokenExpiryMonth'] ?? null,
            'tokenExpiryYear' => $tokenized['tokenExpiryYear'] ?? null,
        ], now()->addHour());
    }
}
