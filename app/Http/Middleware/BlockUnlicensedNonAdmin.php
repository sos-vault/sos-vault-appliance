<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Open-core expiry recovery: on an unlicensed appliance (no license installed
 * OR license expired) any non-admin user is logged out and redirected back to
 * the login screen. Admins can still log in so they can renew the license.
 *
 * Runs on the web middleware group AFTER session/auth so auth()->user() is
 * resolved; no-op on SaaS or licensed appliance because applianceUnlicensed()
 * returns false.
 */
class BlockUnlicensedNonAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (applianceUnlicensed() && Auth::check() && ! Auth::user()->hasRole('admin')) {
            $blockedId = Auth::id();

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            addEvent(
                ['user_id' => $blockedId, 'path' => $request->path()],
                'OPEN_CORE_DEGRADED_LOGIN_BLOCKED',
                'FAILED',
                'ACTIVITY',
                0,
                0,
                $blockedId ?? 0,
                0
            );

            return redirect()
                ->route('login')
                ->with('error', __('licensing.expired_non_admin_blocked'));
        }

        return $next($request);
    }
}
