<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\BusinessType;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property BusinessType|null $business_type
 * @property string|null $website
 * @property string|null $country
 * @property string|null $line1
 * @property string|null $line2
 * @property string|null $city
 * @property string|null $postal_code
 * @property string $default_currency
 * @property array<string, mixed>|null $custom_data
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, ApiKey> $apiKeys
 * @property-read Collection<int, Product> $products
 * @property-read TeamSettings|null $settings
 * @property-read TeamProcessorConnection|null $processorConnection
 */
#[Fillable([
    'name', 'slug', 'is_personal',
    'business_type', 'website', 'country', 'line1', 'line2', 'city', 'postal_code',
    'default_currency', 'custom_data',
])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * The DB column carries the same default, but Eloquent doesn't
     * hydrate DB-applied defaults onto the in-memory instance after an
     * insert — code that reads `$team->default_currency` right after
     * `Team::create()` in the same request would otherwise see null.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'default_currency' => 'NGN',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('is_owner', true)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role_id', 'is_owner'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get all roles defined for this team.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Get this team's billing/workspace settings.
     *
     * @return HasOne<TeamSettings, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(TeamSettings::class);
    }

    /**
     * Get this team's Nomba processor connection.
     *
     * @return HasOne<TeamProcessorConnection, $this>
     */
    public function processorConnection(): HasOne
    {
        return $this->hasOne(TeamProcessorConnection::class);
    }

    /**
     * Get all Bouclay API keys issued to this team.
     *
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * Get all products in this team's catalog.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'business_type' => BusinessType::class,
            'custom_data' => 'array',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
