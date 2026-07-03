<?php

namespace App\Http\Controllers\Developers;

use App\Enums\ApiKeyKind;
use App\Enums\ApiKeyMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Developers\StoreApiKeyRequest;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    /**
     * List the current team's Bouclay API keys.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('viewApiKeys', $team);

        $keys = $team->apiKeys()
            ->with('creator')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
                'mode' => $key->mode,
                'kind' => $key->kind,
                'lastFour' => $key->last_four,
                'creatorName' => $key->creator?->name,
                'createdAt' => $key->created_at?->toISOString(),
                'lastUsedAt' => $key->last_used_at?->toISOString(),
                'revokedAt' => $key->revoked_at?->toISOString(),
            ]);

        return Inertia::render('developers/api-keys', [
            'keys' => $keys,
            'canManage' => $request->user()->toTeamPermissions($team)->canManageApiKeys,
            'liveNombaConnected' => $team->processorConnection?->isConnected(ApiKeyMode::Live) ?? false,
        ]);
    }

    /**
     * Generate a new API key for the current team.
     */
    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;

        Gate::authorize('manageApiKeys', $team);

        $mode = ApiKeyMode::from($request->validated('mode'));

        if ($mode === ApiKeyMode::Live && ! ($team->processorConnection?->isConnected(ApiKeyMode::Live) ?? false)) {
            throw ValidationException::withMessages([
                'mode' => __('Connect a live Nomba account before creating a live key.'),
            ]);
        }

        $kind = ApiKeyKind::from($request->validated('kind'));
        $generated = ApiKey::generate($mode, $kind);

        $key = $team->apiKeys()->create([
            'created_by' => $request->user()->id,
            'name' => $request->validated('name'),
            'mode' => $mode,
            'kind' => $kind,
            'hashed_secret' => $generated['hashedSecret'],
            'last_four' => $generated['lastFour'],
        ]);

        Inertia::flash('generatedKey', [
            'id' => $key->id,
            'name' => $key->name,
            'key' => $generated['key'],
        ]);

        return to_route('developers.api-keys.index');
    }

    /**
     * Revoke an API key belonging to the current team.
     */
    public function destroy(Request $request, ApiKey $api_key): RedirectResponse
    {
        abort_unless($api_key->team_id === $request->user()->currentTeam->id, 404);

        Gate::authorize('manageApiKeys', $request->user()->currentTeam);

        if (! $api_key->isRevoked()) {
            $api_key->revoked_at = now();
            $api_key->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API key revoked.')]);

        return to_route('developers.api-keys.index');
    }
}
