<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentTeam
{
    /**
     * Handle an incoming request.
     *
     * Guards routes that act on the authenticated user's current team without
     * a {team} route parameter (e.g. general business settings, team members,
     * dashboard, developers). A user can end up with no current team (e.g. a
     * fallback team failed to resolve) — send them to pick one instead of a
     * bare 403.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->currentTeam) {
            return redirect()->route('teams.choose');
        }

        return $next($request);
    }
}
