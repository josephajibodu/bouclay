<?php

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * Generates a long-lived, unguessable portal token at creation time so
 * end customers can access their self-service area without a Bouclay account.
 */
trait HasPortalToken
{
    protected static function bootHasPortalToken(): void
    {
        static::creating(function ($model) {
            if (empty($model->portal_token)) {
                $model->portal_token = static::generatePortalToken();
            }
        });
    }

    /**
     * Generate a new portal token value.
     */
    public static function generatePortalToken(): string
    {
        return Str::random(48);
    }

    /**
     * The customer-facing self-service portal URL for this customer.
     */
    public function portalUrl(): string
    {
        return route('portal.show', $this->portal_token);
    }
}
