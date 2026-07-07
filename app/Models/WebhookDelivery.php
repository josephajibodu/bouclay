<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\WebhookDeliveryStatus;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * At-least-once outbound webhook delivery with exponential backoff (schema.md § webhook_deliveries).
 *
 * @property int $id
 * @property string $public_id
 * @property int $webhook_endpoint_id
 * @property int $event_id
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebhookEndpoint $webhookEndpoint
 * @property-read Event $event
 */
#[Fillable(['webhook_endpoint_id', 'event_id', 'status', 'attempts', 'next_attempt_at'])]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasPublicId;

    public const MAX_ATTEMPTS = 5;

    /** @var list<int> Backoff delays in seconds after each failed attempt. */
    public const BACKOFF_SECONDS = [60, 300, 1800, 7200, 86400];

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'whd';
    }

    /**
     * Serialise a row for the dashboard delivery log.
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'publicId' => $this->public_id,
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'nextAttemptAt' => $this->next_attempt_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'event' => [
                'publicId' => $this->event->public_id,
                'type' => $this->event->type->value,
                'createdAt' => $this->event->created_at?->toISOString(),
            ],
            'endpoint' => [
                'id' => $this->webhookEndpoint->id,
                'publicId' => $this->webhookEndpoint->public_id,
                'url' => $this->webhookEndpoint->url,
            ],
        ];
    }

    /**
     * Compute the next retry timestamp after a failed attempt.
     */
    public function nextBackoffAt(): Carbon
    {
        $index = min($this->attempts, count(self::BACKOFF_SECONDS) - 1);

        return Carbon::now()->addSeconds(self::BACKOFF_SECONDS[$index]);
    }

    /**
     * Get the endpoint this delivery targets.
     *
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class);
    }

    /**
     * Get the event being delivered.
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'next_attempt_at' => 'datetime',
        ];
    }
}
