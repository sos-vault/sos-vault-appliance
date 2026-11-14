<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Closure;
use Symfony\Component\HttpFoundation\Response;

class SessionTrack
{
    public function handle(Request $request, Closure $next): Response {
        if ($user = auth()->user()) {
            $route = $request->route()->getName();
            $user->timestamps    = false;
            if($route != "api.vaultState") {
                $user->last_activity = date('U');
            }
            $user->saveQuietly();
        }

        return $next($request);
    }
}
