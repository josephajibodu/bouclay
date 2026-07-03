<?php

namespace App\Models;

use Database\Factories\PriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $price_id
 * @property int $tier_index
 * @property int|null $up_to
 * @property int $unit_amount
 * @property int|null $flat_amount
 * @property-read Price $price
 */
#[Fillable(['price_id', 'tier_index', 'up_to', 'unit_amount', 'flat_amount'])]
class PriceTier extends Model
{
    /** @use HasFactory<PriceTierFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the price this tier belongs to.
     *
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }
}
