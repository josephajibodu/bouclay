<?php

namespace App\Services\Nomba;

use App\Enums\ApiKeyMode;
use App\Models\Team;
use App\Models\TeamProcessorConnection;

/**
 * Resolves which Nomba environment a team's processor operations should run
 * in. A single source of truth so charges, tokenisation, and anything else
 * that talks to Nomba all agree on the mode.
 *
 * Rule: prefer a connected **live** account; fall back to a connected **test**
 * account; `null` when neither is connected.
 */
class NombaModeResolver
{
    /**
     * Resolve the mode for a team.
     */
    public function resolve(Team $team): ?ApiKeyMode
    {
        return $this->forConnection($team->processorConnection);
    }

    /**
     * Resolve the mode for an already-loaded connection (avoids re-querying
     * the relationship when the caller already has it).
     */
    public function forConnection(?TeamProcessorConnection $connection): ?ApiKeyMode
    {
        if ($connection === null) {
            return null;
        }

        return match (true) {
            $connection->isConnected(ApiKeyMode::Live) => ApiKeyMode::Live,
            $connection->isConnected(ApiKeyMode::Test) => ApiKeyMode::Test,
            default => null,
        };
    }
}
