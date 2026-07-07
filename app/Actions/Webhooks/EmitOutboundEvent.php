<?php

namespace App\Actions\Webhooks;

use App\Enums\OutboundEventType;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverOutboundWebhook;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Persist a normalised billing event and queue delivery to active endpoints.
 */
class EmitOutboundEvent
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Team $team, OutboundEventType $type, array $data): void
    {
        DB::afterCommit(function () use ($team, $type, $data): void {
            $event = $team->events()->create([
                'type' => $type,
                'data' => $data,
            ]);

            $team->webhookEndpoints()
                ->where('active', true)
                ->get()
                ->each(function ($endpoint) use ($event): void {
                    $delivery = $endpoint->deliveries()->create([
                        'event_id' => $event->id,
                        'status' => WebhookDeliveryStatus::Pending,
                        'attempts' => 0,
                        'next_attempt_at' => now(),
                    ]);

                    DeliverOutboundWebhook::dispatch($delivery->id);
                });
        });
    }
}
