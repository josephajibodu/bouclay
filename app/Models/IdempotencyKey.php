<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $response_code
 * @property array<string, mixed>|null $response_body
 * @property Carbon|null $locked_at
 * @property Carbon|null $created_at
 * @property-read Team $team
 */
class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'key',
        'request_hash',
        'response_code',
        'response_body',
        'locked_at',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'locked_at' => 'datetime',
        ];
    }
}
