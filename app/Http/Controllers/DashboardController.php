<?php

namespace App\Http\Controllers;

use App\Enums\ApiKeyMode;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $email = strtolower($user->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        return Inertia::render('dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'onboarding' => $user->currentTeam ? $this->onboardingState($user->currentTeam) : null,
        ]);
    }

    /**
     * @return array{businessConfirmed: bool, nombaConnected: bool, apiKeyGenerated: bool, webhookVerified: bool, firstProductCreated: bool, links: array{nomba: string, apiKeys: string, webhooks: string, products: string}}
     */
    private function onboardingState(Team $team): array
    {
        $connection = $team->processorConnection;

        return [
            'businessConfirmed' => $team->line1 !== null && $team->city !== null && $team->country !== null,
            'nombaConnected' => $connection !== null
                && ($connection->isConnected(ApiKeyMode::Test) || $connection->isConnected(ApiKeyMode::Live)),
            'apiKeyGenerated' => $team->apiKeys()->whereNull('revoked_at')->exists(),
            'webhookVerified' => $connection?->webhook_verified_at !== null,
            'firstProductCreated' => $team->products()->exists(),
            'links' => [
                'nomba' => route('developers.nomba.show'),
                'apiKeys' => route('developers.api-keys.index'),
                'webhooks' => route('developers.webhooks.show'),
                'products' => route('catalog.products.index'),
            ],
        ];
    }
}
