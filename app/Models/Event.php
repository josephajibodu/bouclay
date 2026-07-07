<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use App\Enums\OutboundEventType;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Normalised outbound billing event log (schema.md § events).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property OutboundEventType $type
 * @property array<string, mixed> $data
 * @property Carbon|null $created_at
 * @property-read Team $team
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
#[Fillable(['team_id', 'type', 'data'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'evt';
    }

    /**
     * Serialise the event for the integrator webhook payload envelope.
     *
     * @return array<string, mixed>
     */
    public function toWebhookPayload(): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type->value,
            'created' => $this->created_at?->toIso8601String(),
            'data' => $this->data,
        ];
    }

    /**
     * Get the team that owns this event.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get delivery attempts for this event.
     *
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OutboundEventType::class,
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
