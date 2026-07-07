<?php

namespace App\Jobs;

use App\Services\Webhooks\DeliverOutboundWebhookAttempt;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverOutboundWebhook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public int $webhookDeliveryId)
    {
        //
    }

    public function uniqueId(): string
    {
        return (string) $this->webhookDeliveryId;
    }

    public function handle(DeliverOutboundWebhookAttempt $deliver): void
    {
        $deliver->handle($this->webhookDeliveryId);
    }
}
