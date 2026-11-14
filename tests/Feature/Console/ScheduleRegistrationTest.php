<?php

use App\Console\Commands\CloseUnattendedVaults;
use App\Console\Commands\ProcessDiskShrinks;
use App\Console\Commands\PurgeExpiredRecords;
use App\Console\Commands\SendTrialEndEmails;
use Illuminate\Console\Scheduling\Schedule;

it('registers every expected scheduled command', function () {
    $expected = [
        'license:send-expiry-reminders' => '0 9 * * *',
        'subscriptions:cancel-expired' => '0 * * * *',
        'abuseip:update' => '0 0 * * *',
        'vault:remount-public' => '* * * * *',
        'vault:close-unattended' => '*/5 * * * *',
        'db:purge-expired' => '0 * * * *',
        'subscriptions:process-disk-shrinks' => '*/5 * * * *',
        'users:send-trial-end-emails' => '0 6 * * *',
    ];

    $schedule = app(Schedule::class);
    $registered = collect($schedule->events())
        ->mapWithKeys(fn ($event) => [trim(preg_replace("/^.*'?artisan'?\s+/", '', $event->command ?? '')) => $event->expression])
        ->all();

    $missing = [];
    $wrongCron = [];
    foreach ($expected as $signature => $cron) {
        if (! array_key_exists($signature, $registered)) {
            $missing[] = $signature;
        } elseif ($registered[$signature] !== $cron) {
            $wrongCron[$signature] = ['got' => $registered[$signature], 'expected' => $cron];
        }
    }

    expect($missing)->toBe([],
        'Missing schedules: '.implode(', ', $missing).'. All registered: '.json_encode($registered)
    );
    expect($wrongCron)->toBe([],
        'Wrong crons: '.json_encode($wrongCron)
    );
});

it('gates SaaS-only schedules behind isAppliance()', function () {
    $saasOnly = [
        'license:send-expiry-reminders',
        'subscriptions:cancel-expired',
        'subscriptions:process-disk-shrinks',
        'users:send-trial-end-emails',
        'abuseip:update',
        // The appliance has no public-vault concept, so the every-minute
        // auto-remount is SaaS-only (would be pure noise on the appliance).
        'vault:remount-public',
    ];
    $universal = [
        'vault:close-unattended',
        'db:purge-expired',
    ];

    $byCommand = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn ($event) => [trim(preg_replace("/^.*'?artisan'?\s+/", '', $event->command ?? '')) => $event])
        ->all();

    foreach (['saas', 'appliance'] as $mode) {
        config()->set('product.type', $mode);
        $shouldSkipOnAppliance = $mode === 'appliance';

        foreach ($saasOnly as $signature) {
            $passes = $byCommand[$signature]->filtersPass(app());
            expect($passes)->toBe(! $shouldSkipOnAppliance,
                "'{$signature}' in '{$mode}' mode: expected passes=".(! $shouldSkipOnAppliance ? 'true' : 'false').', got '.($passes ? 'true' : 'false')
            );
        }

        foreach ($universal as $signature) {
            expect($byCommand[$signature]->filtersPass(app()))->toBeTrue(
                "'{$signature}' should always run regardless of mode (mode={$mode})"
            );
        }
    }
});

it('boots every new scheduled command class without errors', function () {
    $classes = [
        CloseUnattendedVaults::class,
        PurgeExpiredRecords::class,
        ProcessDiskShrinks::class,
        SendTrialEndEmails::class,
    ];

    foreach ($classes as $class) {
        $cmd = app($class);
        expect($cmd->getName())->not->toBeEmpty($class.' has no signature');
        expect($cmd->getDescription())->not->toBeEmpty($class.' has no description');
    }
});
