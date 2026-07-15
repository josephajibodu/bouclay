<?php

namespace App\Services\Gateways;

use App\Enums\ApiKeyMode;
use App\Models\Team;
use App\Models\TeamProcessorConnection;

/**
 * Resolves which processor environment Bouclay runs in. The active mode is a
 * property of the deployment, not of the gateway: it comes from
 * {@see config('services.nomba.mode')} (default: live) — not from per-request
 * payload inspection or "whichever mode happens to be connected".
 *
 * The config key stays `services.nomba.mode` for continuity with deployments
 * already setting `NOMBA_MODE`; it governs every driver.
 */
class GatewayModeResolver
{
    /**
     * The processor environment this deployment uses.
     */
    public function configuredMode(): ApiKeyMode
    {
        return ApiKeyMode::tryFrom((string) config('services.nomba.mode')) ?? ApiKeyMode::Live;
    }

    /**
     * Resolve the mode for a team when credentials exist for the configured mode.
     */
    public function resolve(Team $team): ?ApiKeyMode
    {
        return $this->forConnection($team->processorConnection);
    }

    /**
     * Resolve the mode for an already-loaded connection.
     */
    public function forConnection(?TeamProcessorConnection $connection): ?ApiKeyMode
    {
        if ($connection === null) {
            return null;
        }

        $mode = $this->configuredMode();

        return $connection->isConnected($mode) ? $mode : null;
    }
}
