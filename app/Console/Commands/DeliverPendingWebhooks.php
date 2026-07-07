<?php

namespace App\Console\Commands;

use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverOutboundWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

class DeliverPendingWebhooks extends Command
{
    protected $signature = 'webhooks:deliver-pending';

    protected $description = 'Dispatch queued outbound webhook deliveries whose retry window has opened';

    public function handle(): int
    {
        $count = 0;

        WebhookDelivery::query()
            ->where('status', WebhookDeliveryStatus::Pending)
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->each(function (WebhookDelivery $delivery) use (&$count): void {
                DeliverOutboundWebhook::dispatch($delivery->id);
                $count++;
            });

        $this->info("Dispatched {$count} pending webhook delivery(ies).");

        return self::SUCCESS;
    }
}
