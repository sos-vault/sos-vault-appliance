<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GzipEncodeResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // this will fix the "The content cannot be set on a StreamedResponse instance" error for large log files
        if ($request->route()) {
            $route = $request->route()->getName();
            // log:info($route);

            // DevDojo/auth doesn't work with Gzip.
            // auth.setup/*, auth.login, auth.register, auth.logout, auth.verify-email.{id}.{hash},
            // auth.{driver}.redirect and auth.{driver}.callback

            if (preg_match("/auth\..*/", $route)) {
                return $response;
            }

            // Filament panels don't work with gzip — Livewire script injection
            // runs after the response and can't inject into compressed content.
            if (preg_match("/filament\..*/", $route)) {
                return $response;
            }
        }

        // Only compress text-based responses
        $contentType = $response->headers->get('Content-Type');
        if (strpos($contentType, 'text/') === false && strpos($contentType, 'json') === false) {
            return $response;
        }

        // Check if the response should be Gzip encoded
        if (! $response->headers->has('Content-Encoding') &&
            in_array('gzip', $request->getEncodings()) &&
            function_exists('gzencode') &&
            ! $response->isRedirection() &&
            ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300)
        ) {
            $content = gzencode($response->getContent(), 9);
            $response->setContent($content);
            $response->headers->set('Content-Encoding', 'gzip');
            $response->headers->set('Vary', 'Accept-Encoding', false);
            $response->headers->set('Content-Length', strlen($content));
        }

        return $response;
    }
}
