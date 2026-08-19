<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTeamManager
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (! $user->hasRole(['Team', 'Enterprise']) && ! $user->hasRole('admin'))) {
            return redirect()->route('settings.profile');
        }

        // Team/Enterprise members who are not the group owner are redirected.
        if (! $user->hasRole('admin') && ! $user->isTeamManager()) {
            return redirect()->route('settings.profile');
        }

        return $next($request);
    }
}
