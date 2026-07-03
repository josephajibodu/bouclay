<?php

namespace App\Models;

use App\Enums\ApiKeyMode;
use Database\Factories\TeamProcessorConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $processor
 * @property string|null $nomba_test_account_id
 * @property string|null $nomba_test_subaccount_id
 * @property string|null $nomba_test_client_id
 * @property string|null $nomba_test_client_secret
 * @property string|null $nomba_live_account_id
 * @property string|null $nomba_live_subaccount_id
 * @property string|null $nomba_live_client_id
 * @property string|null $nomba_live_client_secret
 * @property string $inbound_webhook_token
 * @property string|null $nomba_test_webhook_secret
 * @property string|null $nomba_live_webhook_secret
 * @property Carbon|null $webhook_verified_at
 * @property Carbon|null $test_connected_at
 * @property Carbon|null $live_connected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable([
    'processor',
    'nomba_test_account_id', 'nomba_test_subaccount_id', 'nomba_test_client_id', 'nomba_test_client_secret',
    'nomba_live_account_id', 'nomba_live_subaccount_id', 'nomba_live_client_id', 'nomba_live_client_secret',
    'nomba_test_webhook_secret', 'nomba_live_webhook_secret',
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
     * Get the Nomba credentials for the given mode, or null if not connected.
     *
     * `accountId` is the parent business account and always authenticates
     * (the `accountId` header on the token-issue call). `requestAccountId`
     * is what individual business-operation calls should be scoped to —
     * the subaccount if one is set, otherwise the same parent account.
     *
     * @return array{accountId: string, subaccountId: string|null, requestAccountId: string, clientId: string, clientSecret: string}|null
     */
    public function credentialsFor(ApiKeyMode $mode): ?array
    {
        [$accountId, $subaccountId, $clientId, $clientSecret] = match ($mode) {
            ApiKeyMode::Test => [$this->nomba_test_account_id, $this->nomba_test_subaccount_id, $this->nomba_test_client_id, $this->nomba_test_client_secret],
            ApiKeyMode::Live => [$this->nomba_live_account_id, $this->nomba_live_subaccount_id, $this->nomba_live_client_id, $this->nomba_live_client_secret],
        };

        if (! $accountId || ! $clientId || ! $clientSecret) {
            return null;
        }

        return [
            'accountId' => $accountId,
            'subaccountId' => $subaccountId,
            'requestAccountId' => $subaccountId ?: $accountId,
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
        ];
    }

    /**
     * Determine if a signing secret has been saved for the given mode.
     */
    public function hasWebhookSecret(ApiKeyMode $mode): bool
    {
        return match ($mode) {
            ApiKeyMode::Test => $this->nomba_test_webhook_secret !== null,
            ApiKeyMode::Live => $this->nomba_live_webhook_secret !== null,
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nomba_test_account_id' => 'encrypted',
            'nomba_test_subaccount_id' => 'encrypted',
            'nomba_test_client_id' => 'encrypted',
            'nomba_test_client_secret' => 'encrypted',
            'nomba_live_account_id' => 'encrypted',
            'nomba_live_subaccount_id' => 'encrypted',
            'nomba_live_client_id' => 'encrypted',
            'nomba_live_client_secret' => 'encrypted',
            'nomba_test_webhook_secret' => 'encrypted',
            'nomba_live_webhook_secret' => 'encrypted',
            'webhook_verified_at' => 'datetime',
            'test_connected_at' => 'datetime',
            'live_connected_at' => 'datetime',
        ];
    }
}
