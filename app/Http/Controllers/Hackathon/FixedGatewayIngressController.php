<?php

namespace App\Http\Controllers\Hackathon;

use App\Actions\Webhooks\ReceiveGatewayWebhook;
use App\Hackathon\Gateways\ResolveConnectionFromWebhookPayload;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fixed webhook URL registered for the hackathon demo, where the gateway
 * can't be pointed at `/webhooks/{processor}/{token}`. Once the connection is
 * resolved from the payload, the event goes through the same shared receive
 * path as every other inbound webhook.
 *
 * Detach by setting {@see NOMBA_HACKATHON_INGRESS_ENABLED=false} or removing
 * the route block in {@see routes/web.php} and deleting {@see app/Hackathon/}.
 */
class FixedGatewayIngressController extends Controller
{
    public function __invoke(
        Request $request,
        ResolveConnectionFromWebhookPayload $resolveConnection,
        ReceiveGatewayWebhook $receive,
    ): JsonResponse {
        abort_unless((bool) config('services.nomba.hackathon_ingress.enabled'), 404);

        $connection = $resolveConnection->handle($request->all());

        if ($connection === null) {
            return response()->json(['message' => 'No matching gateway connection.'], 404);
        }

        return $receive->handle($connection, $request);
    }
}
