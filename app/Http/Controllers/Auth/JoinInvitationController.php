<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\AcceptTeamInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\JoinInvitationRequest;
use App\Http\Responses\Concerns\RedirectsToCurrentTeam;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invite-only counterpart to Fortify's registration flow. A user who
 * arrives via an invitation link never sees a business-creation form — they
 * land here, accept, and join the inviting team directly.
 */
class JoinInvitationController extends Controller
{
    use RedirectsToCurrentTeam;

    /**
     * Show the invitation landing page.
     */
    public function show(Request $request, TeamInvitation $invitation): Response
    {
        $viewer = $request->user();

        return Inertia::render('auth/accept-invitation', [
            'invitation' => $invitation->isPending() ? [
                'code' => $invitation->code,
                'teamName' => $invitation->team->name,
                'inviterName' => $invitation->inviter->name,
                'roleName' => $invitation->role->name,
                'email' => $invitation->email,
            ] : null,
            'accountExists' => User::where('email', $invitation->email)->exists(),
            'viewerState' => match (true) {
                ! $viewer => 'guest',
                strtolower($viewer->email) === strtolower($invitation->email) => 'correct-user',
                default => 'wrong-user',
            },
            'viewerEmail' => $viewer?->email,
        ]);
    }

    /**
     * Show the "create your login" form for a guest accepting a fresh
     * invitation. Guest-only — an authenticated user never needs this.
     */
    public function showRegister(TeamInvitation $invitation): Response
    {
        abort_unless($invitation->isPending(), 404);

        return Inertia::render('auth/join-register', [
            'invitation' => [
                'code' => $invitation->code,
                'teamName' => $invitation->team->name,
                'email' => $invitation->email,
            ],
        ]);
    }

    /**
     * Create an account for the invited email and join the inviting team.
     * Guest-only — an authenticated user accepts through the existing
     * `invitations.accept` route instead.
     */
    public function register(JoinInvitationRequest $request, TeamInvitation $invitation, AcceptTeamInvitation $acceptTeamInvitation): RedirectResponse
    {
        $user = DB::transaction(function () use ($request, $invitation, $acceptTeamInvitation) {
            $locked = TeamInvitation::whereKey($invitation->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => __('This invitation is no longer valid.'),
                ]);
            }

            $user = User::create([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'email' => $locked->email,
                'password' => $request->validated('password'),
            ]);

            $acceptTeamInvitation->handle($locked, $user);

            return $user;
        });

        Auth::login($user);

        return redirect()->to($this->redirectPathForCurrentTeam($request, '/dashboard'));
    }

    /**
     * Decline the invitation. Works whether or not the visitor is signed
     * in — the unguessable code in the link is the only proof of identity
     * this action needs.
     */
    public function decline(TeamInvitation $invitation): RedirectResponse
    {
        if ($invitation->isPending()) {
            $invitation->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation declined.')]);

        return to_route('register');
    }
}
