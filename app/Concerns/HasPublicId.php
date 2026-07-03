<?php

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * Generates a Stripe-style prefixed public identifier (e.g. `prod_a1B2c3...`)
 * at creation time — safe to expose in the dashboard and API without
 * revealing the sequential internal `id` or letting it be guessed.
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = $model->publicIdPrefix().'_'.Str::random(24);
            }
        });
    }

    /**
     * The prefix for this model's public identifier, e.g. `prod`, `price`.
     */
    abstract public function publicIdPrefix(): string;
}
