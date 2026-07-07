<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Integrator-owned URL Bouclay POSTs billing events to (schema.md § webhook_endpoints).
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property string $url
 * @property string $signing_secret
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
#[Fillable(['team_id', 'url', 'signing_secret', 'active'])]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory, HasPublicId;

    /**
     * Generate a new signing secret and its display suffix.
     *
     * The raw secret is only ever returned here, at creation/rotation time.
     *
     * @return array{secret: string, lastFour: string}
     */
    public static function generateSigningSecret(): array
    {
        $random = Str::random(32);
        $secret = "whsec_{$random}";

        return [
            'secret' => $secret,
            'lastFour' => substr($random, -4),
        ];
    }

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'whe';
    }

    /**
     * Get the team that registered this endpoint.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get delivery attempts targeting this endpoint.
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
            'signing_secret' => 'encrypted',
            'active' => 'boolean',
        ];
    }
}
