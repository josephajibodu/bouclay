<?php

namespace App\Hackathon\Nomba;

use App\Models\TeamProcessorConnection;

/**
 * Hackathon-only team resolution for Nomba's fixed ingress URL.
 *
 * Nomba cannot be re-pointed at `/webhooks/nomba/{token}` for the demo,
 * so this class maps payload account IDs → a {@see TeamProcessorConnection}.
 *
 * Delete {@see config('services.nomba.hackathon_ingress')}, its route, and
 * this namespace when the hackathon is over.
 */
class ResolveNombaConnectionFromWebhookPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): ?TeamProcessorConnection
    {
        $connection = $this->resolveByAccountId($payload);

        if ($connection !== null) {
            return $connection;
        }

        return $this->resolveByFallbackTeamId();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveByAccountId(array $payload): ?TeamProcessorConnection
    {
        $accountId = $payload['data']['merchant']['userId']
            ?? $payload['data']['order']['accountId']
            ?? null;

        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        // Account IDs are encrypted at rest — compare after decryption.
        return TeamProcessorConnection::query()
            ->get()
            ->first(fn (TeamProcessorConnection $connection): bool => $connection->nomba_test_account_id === $accountId
                || $connection->nomba_live_account_id === $accountId);
    }

    private function resolveByFallbackTeamId(): ?TeamProcessorConnection
    {
        $teamId = config('services.nomba.hackathon_ingress.fallback_team_id');

        if (! is_numeric($teamId)) {
            return null;
        }

        return TeamProcessorConnection::query()
            ->where('team_id', (int) $teamId)
            ->first();
    }
}
