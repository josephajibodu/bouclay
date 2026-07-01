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
     * a {team} route parameter (e.g. general business settings, team members).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(! $request->user()?->currentTeam, 403);

        return $next($request);
    }
}
