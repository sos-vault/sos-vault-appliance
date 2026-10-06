<?php

use App\Http\Middleware\AbuseIp;
use App\Http\Middleware\BlockUnlicensedNonAdmin;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureTeamManager;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Middleware\HandleRedirects;
use App\Http\Middleware\HttpsRedirect;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SessionTrack;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Middleware\VerifyVault;
use App\Providers\AppServiceProvider;
use App\Providers\BillingSettingsServiceProvider;
use App\Providers\ModulesServiceProvider;
use DevDojo\Themes\ThemesServiceProvider;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Lab404\Impersonate\ImpersonateServiceProvider;
use RalphJSmit\Livewire\Urls\Middleware\LivewireUrlsMiddleware;
use Symfony\Component\Mailer\Exception\TransportException;
use Wave\WaveServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        ImpersonateServiceProvider::class,
        WaveServiceProvider::class,
        ThemesServiceProvider::class,
        ThemesServiceProvider::class,
        BillingSettingsServiceProvider::class,
        ModulesServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->use([
            InvokeDeferredCallbacks::class,
            // \App\Http\Middleware\TrustHosts::class,
            TrustProxies::class,
            HttpsRedirect::class,
            HandleCors::class,
            PreventRequestsDuringMaintenance::class,
            ValidatePostSize::class,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            DisableBladeIconComponents::class,
            HandleRedirects::class,
            GzipEncodeResponse::class,
            AbuseIp::class,
        ]);

        $middleware->group('api', [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            SubstituteBindings::class,
        ]);

        $middleware->group('web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            'throttle:web',
            SessionTrack::class,
            LivewireUrlsMiddleware::class,
            SetLocale::class,
            BlockUnlicensedNonAdmin::class,
            RequireTwoFactor::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(AppServiceProvider::HOME);

        $middleware->encryptCookies(except: [
            'theme',
        ]);
        $middleware->validateCsrfTokens(except: [
            '/webhook/paddle',
            '/webhook/stripe',
        ]);

        $middleware->throttleApi();

        $middleware->alias([
            'vault' => VerifyVault::class,
            'team_manager' => EnsureTeamManager::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (TransportException $e, Request $request) {
            Log::warning('Mail transport error: '.$e->getMessage());

            $message = 'Your account was created but the verification email may not have been sent at this time. Please verify your email or you can request a new verification email from your profile.';

            return redirect('/dashboard')
                ->with('status', $message);
        });

        // Defensive guard: a concurrent-update race on the shared Filament
        // notifications toast component (rendered in BOTH the app and marketing
        // layouts) can leave a corrupt `notifications` snapshot in the browser
        // (an int/array where a notification array or scalar prop is expected).
        // On the next poll its hydration throws one of two TypeErrors:
        //   - `Collection::fromLivewire(): ... array, int given`, or
        //   - `Cannot assign array to property Filament\Notifications\Livewire\
        //      Notifications::$isFilamentNotificationsComponent of type bool`.
        // Either 500s every page that renders the toast — including login —
        // wedging the tab until a hard refresh. Until the root cause (the
        // ListDirectories drain/re-dispatch) is reworked, downgrade these to a
        // 419 so Livewire's JS prompts a refresh and the component re-mounts
        // clean, and suppress the error-log spam (kept as a warning for
        // visibility). Scoped tightly so unrelated TypeErrors are untouched.
        $isCorruptNotificationsPayload = fn (TypeError $e): bool => (
            str_contains($e->getMessage(), 'Filament\\Notifications\\Collection')
            && str_contains($e->getMessage(), 'fromLivewire')
        ) || (
            str_contains($e->getMessage(), 'Filament\\Notifications\\Livewire\\Notifications')
            && str_contains($e->getMessage(), 'isFilamentNotificationsComponent')
        );

        $exceptions->report(function (TypeError $e) use ($isCorruptNotificationsPayload) {
            if ($isCorruptNotificationsPayload($e)) {
                Log::warning('Suppressed corrupt Filament notifications payload (returned 419 to self-heal): '.$e->getMessage());

                return false;
            }
        });

        $exceptions->render(function (TypeError $e, Request $request) use ($isCorruptNotificationsPayload) {
            if ($request->is('livewire/update') && $isCorruptNotificationsPayload($e)) {
                return response('This page has expired.', 419);
            }
        });

    })->create();
