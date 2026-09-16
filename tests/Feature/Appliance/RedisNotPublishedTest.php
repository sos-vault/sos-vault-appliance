<?php

/**
 * Redis must never be reachable outside the docker network. The app connects
 * in-network via REDIS_HOST=redis, so the redis service must NOT publish 6379
 * to the host (a `ports:` mapping binds 0.0.0.0 and exposes it to the LAN).
 * This guards against a regression re-adding `ports:` to the redis service.
 */

use Symfony\Component\Yaml\Yaml;

// docker-compose.yml is gitignored (per-deployment, holds host-specific paths),
// so it is not guaranteed to exist on a fresh clone / CI. Skip when absent —
// the guard still runs on dev machines and build hosts where the file lives.
beforeEach(function () {
    if (! is_file(base_path('docker-compose.yml'))) {
        test()->markTestSkipped('docker-compose.yml not present (gitignored).');
    }
});

it('does not publish the redis port to the host in docker-compose.yml', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));

    $redis = $compose['services']['redis'] ?? null;
    expect($redis)->not->toBeNull();

    // No `ports:` (host publish) on redis — only in-network reachability.
    expect($redis)->not->toHaveKey('ports');

    // It should still be exposed in-network so the app can reach it.
    expect($redis['expose'] ?? [])->toContain('6379');
});

it('keeps redis on the internal sail network', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.yml'));

    expect($compose['services']['redis']['networks'] ?? [])->toHaveKey('sail');
});
