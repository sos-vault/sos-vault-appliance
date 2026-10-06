<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\Http\Controllers\Billing\GuestCheckoutController;
use App\Http\Middleware\DenyOnAppliance;
use Illuminate\Support\Facades\Route;
use Wave\Facades\Wave;

// Post-login redirect — handles all auth paths (devdojo, Wave, API). The
// appliance has no Customer Portal, so every authenticated user lands on the app
// dashboard.
Route::get('/auth/home', function () {
    return redirect('/dashboard');
})->middleware('auth')->name('auth.home');

// Appliance has no public marketing landing page. The root path — and its Folio
// aliases (/index, /home all resolve to the same index.blade.php marketing
// page) — redirect to the login screen for guests, or the role-based dashboard
// for an authenticated user. These explicit routes take precedence over Folio's
// catch-all fallback, so the marketing site is never reachable on an appliance
// build. Left unnamed to avoid colliding with the Folio page's name('home')
// (a duplicate route name breaks route:cache).
if (isAppliance()) {
    $applianceLanding = function () {
        return auth()->check()
            ? redirect()->route('auth.home')
            : redirect()->route('login');
    };

    foreach (['/', 'index', 'home'] as $marketingPath) {
        Route::get($marketingPath, $applianceLanding);
    }
}

// Mil AI assistant, popped out into its own chromeless window (see the "detach"
// button in the chat widget). Renders the same ChatWidget component full-window so
// the user can chat while using the app tools in the main window and alt-tab between.
Route::view('/mil', 'mil-detached')
    ->middleware('auth')
    ->name('mil.detached');

// Redirect target after leaving impersonation.
// The password_hash_web session fix is handled in the LeaveImpersonation event
// listener (EventServiceProvider) so it runs in the same request as the leave
// action, before any concurrent Chrome requests can trigger AuthenticateSession.
Route::get('/impersonate/after-leave', function () {
    return redirect('/admin/users');
})->name('impersonate.after-leave');

Route::get('locale/{locale}', function (string $locale) {
    $supported = array_keys(config('app.supported_locales', ['en' => 'English']));
    if (in_array($locale, $supported)) {
        if (auth()->check()) {
            auth()->user()->locale = $locale;
            auth()->user()->save();
        }

        return back()->withCookie(cookie()->forever('locale', $locale));
    }

    return back();
})->name('locale.switch');

// Wave 2.x auth routes — registered before Wave::routes() so they take
// precedence over any package-level redirects for /login and /register.
Route::middleware('web')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', '\Wave\Http\Controllers\Auth\LoginController@showLoginForm')->name('login');
        Route::post('login', '\Wave\Http\Controllers\Auth\LoginController@login')->middleware('throttle:5,1');
        Route::get('register', '\Wave\Http\Controllers\Auth\RegisterController@showRegistrationForm')->name('register');
        Route::post('register', '\Wave\Http\Controllers\Auth\RegisterController@register')->middleware('throttle:3,1');
    });
    // Wave's logout. Previously left UNNAMED because devdojo/auth also
    // registered a route named 'logout' (POST /auth/logout) and naming both
    // broke `php artisan route:cache` (duplicate names). devdojo/auth has been
    // removed, so we reclaim the conventional 'logout' name here.
    Route::post('logout', '\Wave\Http\Controllers\Auth\LoginController@logout')->name('logout');
});

// Guest Paddle checkout — no auth required. Throttled because it is public and
// each call fans out to the Paddle API; the limit is well above a legitimate
// single completion (usually 1–2 posts) while capping replay/amplification abuse.
// DenyOnAppliance: checkout is SaaS-only — the appliance sells nothing in-app.
Route::post('checkout/complete', [GuestCheckoutController::class, 'complete'])
    ->middleware(['throttle:6,1', DenyOnAppliance::class])
    ->name('checkout.complete');

// OAuth social auth — explicitly registered before Wave::routes() and devdojo/auth routes
// to guarantee sosIAMSController always handles these, regardless of vendor state.
Route::middleware('web')->group(function () {
    Route::get('auth/{provider}', '\Wave\Http\Controllers\sosIAMSController@login')->name('wave.social.login');
    Route::get('auth/{provider}/callback', '\Wave\Http\Controllers\sosIAMSController@callback')->name('wave.social.callback');
});

// Wave routes
Wave::routes();
