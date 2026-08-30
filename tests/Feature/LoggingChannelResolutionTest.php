<?php

use Illuminate\Log\LogManager;

/**
 * Guards against the production outage where `LOG_CHANNEL=stdout` was set in
 * a Docker .env but `config/logging.php` defined no `stdout` channel — every
 * log call fell back to the emergency logger and scheduled commands exited 1.
 *
 * LogManager::channel() swallows resolution errors and silently returns an
 * emergency logger, so we exercise the protected ::resolve() method directly
 * to see real failures.
 */
function resolvesCleanly(string $name): ?string
{
    /** @var LogManager $manager */
    $manager = app('log');
    $resolve = new ReflectionMethod(LogManager::class, 'resolve');
    $resolve->setAccessible(true);

    try {
        $resolve->invoke($manager, $name);

        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

it('defines every channel that can appear in LOG_CHANNEL for our deployments', function () {
    // Channels we rely on across .env templates and docker-compose configs.
    $required = ['stack', 'single', 'daily', 'stderr', 'stdout', 'syslog', 'errorlog'];

    $failures = [];
    foreach ($required as $channel) {
        if ($err = resolvesCleanly($channel)) {
            $failures[$channel] = $err;
        }
    }

    expect($failures)->toBe([],
        'Channels referenced as LOG_CHANNEL options that cannot be resolved: '.json_encode($failures)
    );
});

it('resolves the stdout channel to php://stdout', function () {
    expect(resolvesCleanly('stdout'))->toBeNull();

    $cfg = config('logging.channels.stdout');
    expect($cfg['driver'])->toBe('monolog');
    expect($cfg['with']['stream'] ?? null)->toBe('php://stdout');
});
