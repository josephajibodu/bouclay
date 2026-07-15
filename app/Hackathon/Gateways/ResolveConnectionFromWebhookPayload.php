<?php

namespace App\Hackathon\Gateways;

use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayManager;

/**
 * Hackathon-only team resolution for a gateway's fixed ingress URL.
 *
 * A gateway that can't be re-pointed at `/webhooks/{processor}/{token}` sends
 * events to a URL with no token in it, so the team has to be recovered from
 * the payload. Which payload fields identify an account, and which stored
 * credentials to match them against, are both driver questions — this asks
 * `identifiesConnection()` rather than knowing any of it.
 *
 * Delete {@see config('services.nomba.hackathon_ingress')}, its route, and
 * this namespace when the hackathon is over.
 */
class ResolveConnectionFromWebhookPayload
{
    public function __construct(private readonly GatewayManager $gateways)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, string $processor = 'nomba'): ?TeamProcessorConnection
    {
        if (! $this->gateways->has($processor)) {
            return null;
        }

        $connection = $this->resolveByPayload($payload, $processor);

        if ($connection !== null) {
            return $connection;
        }

        return $this->resolveByFallbackTeamId($processor);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveByPayload(array $payload, string $processor): ?TeamProcessorConnection
    {
        $driver = $this->gateways->driver($processor);

        // Credentials are encrypted at rest, so this can't be a WHERE clause —
        // each candidate is decrypted and offered to the driver to claim.
        return TeamProcessorConnection::query()
            ->where('processor', $processor)
            ->get()
            ->first(fn (TeamProcessorConnection $connection): bool => $driver->identifiesConnection($connection, $payload));
    }

    private function resolveByFallbackTeamId(string $processor): ?TeamProcessorConnection
    {
        $teamId = config('services.nomba.hackathon_ingress.fallback_team_id');

        if (! is_numeric($teamId)) {
            return null;
        }

        return TeamProcessorConnection::query()
            ->where('team_id', (int) $teamId)
            ->where('processor', $processor)
            ->first();
    }
}
