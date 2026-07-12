<?php

namespace App\Models;

use Database\Factories\EntitlementGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Polymorphic join: which plan/product grants which entitlement
 * (schema.md §4). `grantor_type` stores the enforced morph alias
 * (`plan` / `product` — see AppServiceProvider), never a class FQN.
 * Grants are catalog-only by design; a `customer` alias is reserved for
 * future per-customer grants (additive, no migration).
 *
 * @property int $id
 * @property int $team_id
 * @property int $entitlement_id
 * @property string $grantor_type
 * @property int $grantor_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Entitlement $entitlement
 * @property-read Plan|Product $grantor
 */
#[Fillable(['team_id', 'entitlement_id', 'grantor_type', 'grantor_id'])]
class EntitlementGrant extends Model
{
    /** @use HasFactory<EntitlementGrantFactory> */
    use HasFactory;

    /**
     * Get the team this grant belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the entitlement being granted.
     *
     * @return BelongsTo<Entitlement, $this>
     */
    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }

    /**
     * Get the plan or product conferring the grant.
     *
     * @return MorphTo<Model, $this>
     */
    public function grantor(): MorphTo
    {
        return $this->morphTo();
    }
}
