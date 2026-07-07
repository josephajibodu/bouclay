<?php

namespace App\Support\Api;

use App\Enums\ApiKeyMode;
use App\Models\ApiKey;
use App\Models\Team;

/**
 * Authenticated API request context bound by {@see AuthenticateApiKey}.
 */
readonly class ApiContext
{
    public function __construct(
        public Team $team,
        public ApiKeyMode $mode,
        public ApiKey $apiKey,
    ) {
        //
    }
}
