<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        if (auth()->check()) {
            $locale = auth()->user()->locale;
        }

        if (! $locale) {
            $locale = $request->cookie('locale');
        }

        if (! $locale) {
            $browserLocale = substr($request->server('HTTP_ACCEPT_LANGUAGE', 'en'), 0, 2);
            $supported = array_keys(config('app.supported_locales', ['en' => 'English']));
            $locale = in_array($browserLocale, $supported) ? $browserLocale : null;
        }

        if ($locale && array_key_exists($locale, config('app.supported_locales', ['en' => 'English']))) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
