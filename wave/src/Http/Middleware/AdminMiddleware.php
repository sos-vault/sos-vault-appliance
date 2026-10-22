<?php

namespace Wave\Http\Middleware;

use Illuminate\Http\Request;
use Closure;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if( !auth()->user()->hasRole('admin') ){
            return redirect()->route('home');
        }

        return $next($request);
    }
}
