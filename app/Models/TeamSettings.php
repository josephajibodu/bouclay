<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $invoice_prefix
 * @property int $next_invoice_number
 * @property string|null $invoice_template
 * @property string|null $invoice_footer
 * @property string $billing_timezone
 * @property string $tax_behavior
 * @property array<string, mixed>|null $dunning_config
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable([
    'invoice_prefix', 'next_invoice_number', 'invoice_template', 'invoice_footer',
    'billing_timezone', 'tax_behavior', 'dunning_config',
])]
class TeamSettings extends Model
{
    /**
     * Get the team these settings belong to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dunning_config' => 'array',
        ];
    }
}
