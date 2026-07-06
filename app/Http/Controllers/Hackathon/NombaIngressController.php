<?php

namespace App\Http\Controllers\Hackathon;

use App\Actions\Webhooks\ReceiveNombaWebhook;
use App\Hackathon\Nomba\ResolveNombaConnectionFromWebhookPayload;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fixed Nomba webhook URL registered for the hackathon demo.
 *
 * Detach by setting {@see NOMBA_HACKATHON_INGRESS_ENABLED=false} or removing
 * the route block in {@see routes/web.php} and deleting {@see app/Hackathon/}.
 */
class NombaIngressController extends Controller
{
    public function __invoke(
        Request $request,
        ResolveNombaConnectionFromWebhookPayload $resolveConnection,
        ReceiveNombaWebhook $receive,
    ): JsonResponse {
        abort_unless((bool) config('services.nomba.hackathon_ingress.enabled'), 404);

        $connection = $resolveConnection->handle($request->all());

        if ($connection === null) {
            return response()->json(['message' => 'No matching Nomba connection.'], 404);
        }

        return $receive->handle($connection, $request);
    }
}
