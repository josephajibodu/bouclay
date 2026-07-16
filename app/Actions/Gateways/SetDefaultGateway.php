<?php

namespace App\Actions\Gateways;

use App\Models\Team;
use App\Models\TeamProcessorConnection;
use App\Services\Gateways\GatewayManager;
use Illuminate\Support\Facades\DB;

/**
 * Choose which gateway a team's *new* checkouts go through
 * (IMPLEMENTATION_V2 §V2-4b).
 *
 * Scope matters: this governs new checkouts only. A stored card always
 * charges through the gateway that minted its token — that rule is enforced
 * by {@see GatewayManager::forPaymentMethod()} and
 * changing the default never re-routes an existing subscription.
 */
class SetDefaultGateway
{
    /**
     * Make one connection the team's default, demoting whichever held it.
     */
    public function handle(Team $team, TeamProcessorConnection $connection): void
    {
        DB::transaction(function () use ($team, $connection) {
            $team->processorConnections()
                ->whereKeyNot($connection->getKey())
                ->update(['is_default' => false]);

            $connection->forceFill(['is_default' => true])->save();
        });
    }

    /**
     * Repair the default after a disconnect.
     *
     * Without this a team can be left defaulted to a gateway it just
     * disconnected — new checkouts would then fail on a connection that has
     * no credentials, which reads as a Bouclay bug rather than a setup step.
     */
    public function ensureConnectedDefault(Team $team): void
    {
        $connections = $team->processorConnections()->orderBy('id')->get();
        $current = $connections->firstWhere('is_default', true);

        if ($current && $current->hasAnyConnection()) {
            return;
        }

        $replacement = $connections->first(
            fn (TeamProcessorConnection $connection): bool => $connection->hasAnyConnection(),
        );

        if ($replacement === null) {
            // Nothing connected at all — leave the flag where it is rather
            // than invent a default the team never chose.
            return;
        }

        $this->handle($team, $replacement);
    }
}
