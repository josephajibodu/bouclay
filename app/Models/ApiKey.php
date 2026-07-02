<?php

namespace App\Models;

use App\Enums\ApiKeyKind;
use App\Enums\ApiKeyMode;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property int|null $created_by
 * @property string $name
 * @property ApiKeyMode $mode
 * @property ApiKeyKind $kind
 * @property string $hashed_secret
 * @property string|null $last_four
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read User|null $creator
 */
#[Fillable(['team_id', 'created_by', 'name', 'mode', 'kind', 'hashed_secret', 'last_four'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    /**
     * Generate a new raw key, its hash, and its last-four for display.
     *
     * The raw key is only ever returned here, at creation time — the model
     * never stores or reveals it again.
     *
     * @return array{key: string, hashedSecret: string, lastFour: string}
     */
    public static function generate(ApiKeyMode $mode, ApiKeyKind $kind): array
    {
        $prefix = match ($kind) {
            ApiKeyKind::Publishable => 'pk',
            ApiKeyKind::Secret => 'sk',
        };

        $random = Str::random(32);
        $key = "{$prefix}_{$mode->value}_{$random}";

        return [
            'key' => $key,
            'hashedSecret' => hash('sha256', $key),
            'lastFour' => substr($random, -4),
        ];
    }

    /**
     * Get the team this key belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user who created this key.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine if the key has been revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ApiKeyMode::class,
            'kind' => ApiKeyKind::class,
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
