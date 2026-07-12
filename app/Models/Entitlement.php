<?php

namespace App\Models;

use App\Concerns\HasPublicId;
use Database\Factories\EntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named capability the application checks against — decoupled from
 * billing state by design (schema.md §4). What a customer can ACCESS is a
 * separate concept from what they've PAID for.
 *
 * @property int $id
 * @property string $public_id
 * @property int $team_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, EntitlementGrant> $grants
 */
#[Fillable(['team_id', 'code', 'name', 'description'])]
class Entitlement extends Model
{
    /** @use HasFactory<EntitlementFactory> */
    use HasFactory, HasPublicId;

    /**
     * Get the prefix for this model's public identifier.
     */
    public function publicIdPrefix(): string
    {
        return 'ent';
    }

    /**
     * Get the team this entitlement belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the plans/products that grant this entitlement.
     *
     * @return HasMany<EntitlementGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(EntitlementGrant::class);
    }
}
