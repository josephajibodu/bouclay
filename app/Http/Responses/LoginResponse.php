<?php

namespace App\Http\Responses;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Models\TeamInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $this->acceptPendingInvitation($request);

        return $request->wantsJson()
            ? new JsonResponse(['two_factor' => false], 200)
            : redirect()->intended(Fortify::redirects('login'));
    }

    /**
     * Join the inviting team when the user logged in from the "log in to
     * accept" path on the invitation landing page. Best-effort: an invalid
     * or stale invitation code never blocks a successful login.
     */
    private function acceptPendingInvitation(Request $request): void
    {
        $code = $request->input('invitation');

        if (! is_string($code) || $code === '') {
            return;
        }

        $user = $request->user();
        $invitation = TeamInvitation::where('code', $code)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return;
        }

        if (strtolower($invitation->email) !== strtolower($user->email)) {
            return;
        }

        app(AcceptTeamInvitation::class)->handle($invitation, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Joined :team.', ['team' => $invitation->team->name])]);
    }
}
