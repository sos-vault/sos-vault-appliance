<?php

/**
 * ApplianceNetworkSettings mirrors the appliance host/port out of the settings
 * table into .env (APP_URL) and docker-compose.yml (nginx :443 host-port).
 * File paths are injected so the real infra files are never touched.
 */

use App\Services\ApplianceNetworkSettings;
use Wave\Setting;

function makeTempEnv(string $appUrl = 'https://sos-vault.com:8080'): string
{
    $path = tempnam(sys_get_temp_dir(), 'env_');
    file_put_contents($path, "APP_NAME=SOS\nAPP_URL={$appUrl}\nDB_CONNECTION=sqlite\n");

    return $path;
}

function makeTempCompose(): string
{
    $path = tempnam(sys_get_temp_dir(), 'compose_');
    file_put_contents($path, "services:\n    nginx:\n        ports:\n          - 8080:443\n          - 8081:80\n");

    return $path;
}

it('returns the OS hostname (or localhost) as the default host', function () {
    expect(ApplianceNetworkSettings::osHostname())->toBeString()->not->toBe('');
});

it('defaults host to the OS hostname and port to 2002 when nothing is saved', function () {
    expect(ApplianceNetworkSettings::currentHost())->toBe(ApplianceNetworkSettings::osHostname())
        ->and(ApplianceNetworkSettings::currentPort())->toBe(2002);
});

it('persists host and port to the settings table', function () {
    $env = makeTempEnv();
    $compose = makeTempCompose();

    (new ApplianceNetworkSettings($env, $compose))->apply('vault.example.com', 9443);

    expect(Setting::where('key', 'appliance.host')->value('value'))->toBe('vault.example.com')
        ->and(Setting::where('key', 'appliance.port')->value('value'))->toBe('9443')
        ->and(ApplianceNetworkSettings::currentHost())->toBe('vault.example.com')
        ->and(ApplianceNetworkSettings::currentPort())->toBe(9443);

    unlink($env);
    unlink($compose);
});

it('rewrites APP_URL in .env', function () {
    $env = makeTempEnv();
    $compose = makeTempCompose();

    $updated = (new ApplianceNetworkSettings($env, $compose))->apply('vault.example.com', 9443);

    expect(file_get_contents($env))->toContain('APP_URL=https://vault.example.com:9443')
        ->and(file_get_contents($env))->not->toContain(':8080')
        ->and($updated)->toContain($env);

    unlink($env);
    unlink($compose);
});

it('rewrites only the nginx :443 host-port mapping in docker-compose.yml', function () {
    $env = makeTempEnv();
    $compose = makeTempCompose();

    (new ApplianceNetworkSettings($env, $compose))->apply('vault.example.com', 9443);

    $yaml = file_get_contents($compose);
    expect($yaml)->toContain('- 9443:443')   // HTTPS mapping updated
        ->and($yaml)->not->toContain('- 8080:443')
        ->and($yaml)->toContain('- 8081:80'); // HTTP mapping untouched

    unlink($env);
    unlink($compose);
});

it('rejects an invalid host (newline / metacharacters) instead of writing it', function () {
    $env = makeTempEnv();
    $compose = makeTempCompose();
    $svc = new ApplianceNetworkSettings($env, $compose);

    expect(fn () => $svc->apply("vault.local\nAPP_KEY=leaked", 2002))
        ->toThrow(InvalidArgumentException::class);
    expect(file_get_contents($env))->not->toContain('APP_KEY=leaked');

    expect(ApplianceNetworkSettings::isValidHost('vault.example.com'))->toBeTrue()
        ->and(ApplianceNetworkSettings::isValidHost("a\nb"))->toBeFalse()
        ->and(ApplianceNetworkSettings::isValidHost('a b'))->toBeFalse()
        ->and(ApplianceNetworkSettings::isValidHost('$(reboot)'))->toBeFalse();

    unlink($env);
    unlink($compose);
});

it('appends APP_URL when the .env has none', function () {
    $env = tempnam(sys_get_temp_dir(), 'env_');
    file_put_contents($env, "APP_NAME=SOS\n");
    $compose = makeTempCompose();

    (new ApplianceNetworkSettings($env, $compose))->apply('host.local', 2002);

    expect(file_get_contents($env))->toContain('APP_URL=https://host.local:2002');

    unlink($env);
    unlink($compose);
});
