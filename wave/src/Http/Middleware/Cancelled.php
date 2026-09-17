<?php

namespace Wave\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// use TCG\Voyager\Models\Role;

class Cancelled
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (auth()->user()->role->name == 'cancelled') {
            return redirect()->route('wave.cancelled');
        }

        return $next($request);
    }
}
