<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Webhooks\ReceiveGatewayWebhook;
use App\Http\Controllers\Controller;
use App\Models\TeamProcessorConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one inbound endpoint every gateway posts to:
 * `/webhooks/{processor}/{token}`. The token identifies the team connection
 * (it's the shared secret in the URL); `processor` says which driver's rules
 * apply, and must agree with the connection the token resolves to — a token
 * from one gateway can't be replayed against another's parser.
 */
class GatewayWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $processor,
        string $token,
        ReceiveGatewayWebhook $receive,
    ): JsonResponse {
        $connection = TeamProcessorConnection::query()
            ->where('inbound_webhook_token', $token)
            ->where('processor', $processor)
            ->first();

        abort_if(! $connection, 404);

        return $receive->handle($connection, $request);
    }
}
