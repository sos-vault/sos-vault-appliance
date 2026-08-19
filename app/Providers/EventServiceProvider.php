<?php

namespace App\Providers;

use App\Events\AdjustVault;
use App\Events\ExpandVault;
use App\Events\FixSosHtmlRequested;
use App\Events\JIRADownload;
use App\Events\SendUserEmail;
use App\Events\ShrinkVault;
use App\Listeners\FixSosHtml;
use App\Listeners\initializeVault;
use App\Listeners\JIRADownloader;
use App\Listeners\ResizeVault;
use App\Listeners\SendEmailListener;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Registered::class => [
        //     SendEmailVerificationNotification::class,
        // ],
        'Illuminate\Auth\Events\Login' => [
            'App\Listeners\initializeVault',
        ],
        'Illuminate\Auth\Events\Logout' => [
            'App\Listeners\closeVault',
        ],
        ExpandVault::class => [
            ResizeVault::class,
        ],
        ShrinkVault::class => [
            ResizeVault::class,
        ],
        AdjustVault::class => [
            ResizeVault::class,
        ],
        JIRADownload::class => [
            JIRADownloader::class,
        ],
        SendUserEmail::class => [
            SendEmailListener::class,
        ],
        FixSosHtmlRequested::class => [
            FixSosHtml::class,
        ],
    ];

    /**
     * Prevent the framework's standalone base-class instance of
     * EventServiceProvider from auto-discovering app/Listeners, which would
     * register every ShouldQueue listener a second time (e.g. SendEmailListener
     * appearing as both "Class" and "Class@handle"), causing the static dedup in
     * SendEmailListener to suppress every second email dispatch.
     */
    public function register(): void
    {
        ServiceProvider::disableEventDiscovery();
        parent::register();
    }

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        Event::listen(TakeImpersonation::class, function (TakeImpersonation $event): void {
            $user = $event->impersonated;
            if ($user->hasRole('Self-hosted')) {
                return;
            }
            $vtools = new VaultTools($user);
            if ($vtools->vaultExists()) {
                $vtools->openVault();
                initializeVault::registerActiveUser($vtools->getVaultId(), $user->id);
            }
        });

        Event::listen(LeaveImpersonation::class, function (LeaveImpersonation $event): void {
            // Fix the password_hash_web session key so Filament's AuthenticateSession
            // middleware doesn't log out the admin after quietLogin() restores them.
            // quietLogin() only updates auth_web (user ID) but not password_hash_web.
            session()->put('password_hash_web', $event->impersonator->getAuthPassword());

            $user = $event->impersonated;
            if ($user->hasRole('Self-hosted')) {
                return;
            }
            $vtools = new VaultTools($user);
            $vid = $vtools->getVaultId();
            if ($vid && initializeVault::deregisterActiveUser($vid, $user->id)) {
                $vtools->closeVault();
            }
        });
    }

    /**
     * Prevent the framework from auto-registering SendEmailVerificationNotification.
     * Verification emails are sent manually in RegisterController::create() using
     * the custom SendUserEmail event, so a second auto-triggered email is not needed.
     */
    protected function configureEmailVerification(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
