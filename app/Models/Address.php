<?php

namespace App\Models;

use App\Enums\AddressType;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $customer_id
 * @property AddressType $type
 * @property string|null $name
 * @property string $line1
 * @property string|null $line2
 * @property string|null $city
 * @property string|null $region
 * @property string|null $postal_code
 * @property string $country
 * @property string|null $phone
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Customer $customer
 */
#[Fillable([
    'team_id', 'customer_id', 'type', 'name', 'line1', 'line2',
    'city', 'region', 'postal_code', 'country', 'phone', 'is_default',
])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /**
     * Get the team this address belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the customer this address belongs to.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Collapse the address to a single human-readable line.
     */
    public function toSingleLine(): string
    {
        return collect([$this->line1, $this->line2, $this->city, $this->region, $this->postal_code, $this->country])
            ->filter()
            ->implode(', ');
    }

    /**
     * Serialise for the customer dashboard.
     *
     * @return array<string, mixed>
     */
    public function toDashboardArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'region' => $this->region,
            'postalCode' => $this->postal_code,
            'country' => $this->country,
            'phone' => $this->phone,
            'isDefault' => $this->is_default,
            'singleLine' => $this->toSingleLine(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'is_default' => 'boolean',
        ];
    }
}
