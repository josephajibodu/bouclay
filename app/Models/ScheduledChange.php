<?php

namespace App\Models;

use App\Enums\ScheduledChangeAction;
use Database\Factories\ScheduledChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $subscription_id
 * @property ScheduledChangeAction $action
 * @property Carbon $effective_at
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription $subscription
 */
#[Fillable([
    'subscription_id', 'action', 'effective_at', 'payload', 'applied_at',
])]
class ScheduledChange extends Model
{
    /** @use HasFactory<ScheduledChangeFactory> */
    use HasFactory;

    /**
     * Get the subscription this change is queued against.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ScheduledChangeAction::class,
            'effective_at' => 'datetime',
            'applied_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
