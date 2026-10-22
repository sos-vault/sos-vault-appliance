<?php

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class HandleRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = '/'.ltrim($request->getPathInfo(), '/');

        $redirects = Cache::remember('url_redirects', 3600, function () {
            return Redirect::where('active', true)
                ->get(['from_path', 'to_path', 'status_code'])
                ->keyBy('from_path')
                ->toArray();
        });

        if (isset($redirects[$path])) {
            $entry = $redirects[$path];

            return redirect($entry['to_path'], $entry['status_code'] ?? 301);
        }

        return $next($request);
    }
}
