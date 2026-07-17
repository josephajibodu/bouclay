<?php

namespace App\Models;

use App\Enums\ApiKeyMode;
use App\Enums\PaymentProcessor;
use Database\Factories\TeamProcessorConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * BYOK link between a team and one payment gateway (schema.md §1). One row
 * per processor per team; `is_default` governs which gateway NEW checkouts
 * route through — charges on a stored card always go through the processor
 * that minted the token.
 *
 * Credentials are one encrypted JSON blob per mode, keyed entirely by the
 * driver's `configSchema()` manifest. This model deliberately does not know
 * what any of those keys mean: reading a gateway's own shape out of the blob
 * is that driver's job, which is what lets a new gateway ship without
 * touching this class.
 *
 * @property int $id
 * @property int $team_id
 * @property string $processor
 * @property bool $is_default
 * @property array<string, string|null>|null $test_credentials
 * @property array<string, string|null>|null $live_credentials
 * @property string $inbound_webhook_token
 * @property Carbon|null $webhook_verified_at
 * @property Carbon|null $test_connected_at
 * @property Carbon|null $live_connected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable([
    'processor',
    'is_default',
    'test_credentials',
    'live_credentials',
    'webhook_verified_at',
    'test_connected_at', 'live_connected_at',
])]
class TeamProcessorConnection extends Model
{
    /** @use HasFactory<TeamProcessorConnectionFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (TeamProcessorConnection $connection) {
            if (empty($connection->inbound_webhook_token)) {
                $connection->inbound_webhook_token = Str::random(40);
            }
        });
    }

    /**
     * Get the team this connection belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Determine if credentials are saved for the given mode.
     */
    public function isConnected(ApiKeyMode $mode): bool
    {
        return match ($mode) {
            ApiKeyMode::Test => $this->test_connected_at !== null,
            ApiKeyMode::Live => $this->live_connected_at !== null,
        };
    }

    /**
     * Whether this gateway is usable at all — connected in either mode.
     */
    public function hasAnyConnection(): bool
    {
        return $this->isConnected(ApiKeyMode::Test) || $this->isConnected(ApiKeyMode::Live);
    }

    /**
     * Get the raw credential blob for the given mode.
     *
     * @return array<string, string|null>
     */
    public function credentialBlobFor(ApiKeyMode $mode): array
    {
        $blob = match ($mode) {
            ApiKeyMode::Test => $this->test_credentials,
            ApiKeyMode::Live => $this->live_credentials,
        };

        return $blob ?? [];
    }

    /**
     * Merge values into the credential blob for the given mode. Null values
     * are dropped from the blob (an omitted secret keeps its saved value —
     * callers merge before saving).
     *
     * @param  array<string, string|null>  $values
     */
    public function mergeCredentials(ApiKeyMode $mode, array $values): void
    {
        $blob = array_filter(
            array_merge($this->credentialBlobFor($mode), $values),
            fn (?string $value): bool => $value !== null && $value !== '',
        );

        match ($mode) {
            ApiKeyMode::Test => $this->test_credentials = $blob,
            ApiKeyMode::Live => $this->live_credentials = $blob,
        };
    }

    /**
     * The gateway's display name, for copy shown to a human — "your card goes
     * to X". `processor` is a driver-registry key and may name a driver
     * registered at runtime with no enum case, so an unknown key resolves to
     * null rather than throwing; callers fall back to generic wording.
     */
    public function processorLabel(): ?string
    {
        return PaymentProcessor::tryFrom($this->processor)?->label();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'test_credentials' => 'encrypted:array',
            'live_credentials' => 'encrypted:array',
            'webhook_verified_at' => 'datetime',
            'test_connected_at' => 'datetime',
            'live_connected_at' => 'datetime',
        ];
    }
}
