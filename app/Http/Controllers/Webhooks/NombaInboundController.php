<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Webhooks\ReceiveNombaWebhook;
use App\Http\Controllers\Controller;
use App\Models\TeamProcessorConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NombaInboundController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        ReceiveNombaWebhook $receive,
    ): JsonResponse {
        $connection = TeamProcessorConnection::query()
            ->where('inbound_webhook_token', $token)
            ->first();

        abort_if(! $connection, 404);

        return $receive->handle($connection, $request);
    }
}
