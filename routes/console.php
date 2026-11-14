<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->describe('Display an inspiring quote');

// Tasks that run on every deployment (SaaS and appliance).
Schedule::command('vault:close-unattended')->everyFiveMinutes();
Schedule::command('db:purge-expired')->hourly();

// Tasks that run on the appliance side only.
$applianceOnly = fn () => isAppliance();

Schedule::command('sos-vault:check-license-expiry')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping()
    ->when($applianceOnly);

// Tasks that run on the SaaS side only — skip on appliance deployments.
$saasOnly = fn () => ! isAppliance();

// The "public" vault auto-remount keeps multi-tenant SaaS public vaults mounted
// for cross-user access. The appliance has no public-vault concept, so this
// every-minute job is pure noise there — skip it on appliance deployments.
Schedule::command('vault:remount-public')->everyMinute()->runInBackground()->when($saasOnly);

Schedule::command('license:send-expiry-reminders')
    ->dailyAt('09:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->when($saasOnly);

// subscriptions:cancel-expired lives in the wave/ package; wrap with scheduler
// hooks since we can't modify the third-party command to emit events directly.
Schedule::command('subscriptions:cancel-expired')
    ->hourly()
    ->when($saasOnly)
    ->onSuccess(fn () => addEvent(
        (object) ['message' => 'subscriptions:cancel-expired completed'],
        'SCHEDULER', 'SUCCESS', 'ACTIVITY', 0, 0, 0, 0
    ))
    ->onFailure(fn () => addEvent(
        (object) ['message' => 'subscriptions:cancel-expired failed (non-zero exit)'],
        'SCHEDULER', 'FAILED', 'ACTIVITY', 0, 0, 0, 0
    ));
Schedule::command('subscriptions:process-disk-shrinks')->everyFiveMinutes()->when($saasOnly);
Schedule::command('users:send-trial-end-emails')->dailyAt('06:00')->when($saasOnly);
Schedule::command('abuseip:update')->daily()->when($saasOnly);
