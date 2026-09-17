<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces two-factor authentication for the current session:
 *
 *  - any user who has enrolled must pass a TOTP/recovery challenge once per
 *    session before reaching protected pages;
 *  - administrators MUST enrol (mandatory) — they are funnelled to the security
 *    settings page until they do.
 *
 * Optional for everyone else. Runs in the web group and the admin panel.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Paths required to satisfy or escape the requirement itself. Matched by
        // path (not route name) so this never depends on Folio name resolution.
        if ($request->is('two-factor-challenge', 'logout', 'locale/*', 'livewire/*')) {
            return $next($request);
        }

        // Anyone who enabled 2FA must pass the per-session challenge first.
        if ($user->hasTwoFactorEnabled() && ! $request->session()->get('2fa_passed')) {
            return redirect()->guest('/two-factor-challenge');
        }

        // Mandatory enrolment for admins — keep them on the enrolment page.
        // Gated by a setting (default on) so an operator can relax it, e.g. on
        // an airgapped box where an admin cannot reach an authenticator app.
        if (setting('auth.two_factor_required_for_admins', true)
            && $user->hasRole('admin') && ! $user->hasTwoFactorEnabled()) {
            if ($request->is('settings/security')) {
                return $next($request);
            }

            return redirect('/settings/security');
        }

        return $next($request);
    }
}
