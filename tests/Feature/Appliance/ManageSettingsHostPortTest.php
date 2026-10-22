<?php

/**
 * The appliance "Host & Port" section of the Manage Settings page persists the
 * hostname + HTTPS port, mirrors them into .env / docker-compose.yml (via an
 * injected ApplianceNetworkSettings bound to temp files), and warns the admin
 * to restart.
 */

use App\Filament\Pages\ManageSettings;
use App\Models\User;
use App\Services\ApplianceNetworkSettings;
use Database\Seeders\RolesTableSeeder;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Wave\Setting;

beforeEach(function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

it('saves host & port, rewrites infra files, and warns to restart', function () {
    $env = tempnam(sys_get_temp_dir(), 'env_');
    file_put_contents($env, "APP_URL=https://old.example:8080\n");
    $compose = tempnam(sys_get_temp_dir(), 'compose_');
    file_put_contents($compose, "    nginx:\n        ports:\n          - 8080:443\n          - 8081:80\n");

    // Bind the service to temp files so the real .env / docker-compose.yml
    // are never touched by the test.
    app()->bind(ApplianceNetworkSettings::class, fn () => new ApplianceNetworkSettings($env, $compose));

    Livewire::test(ManageSettings::class)
        ->set('data.appliance.host', 'vault.local')
        ->set('data.appliance.port', '2002')
        ->call('saveHostPort');

    expect(Setting::get('appliance.host'))->toBe('vault.local')
        ->and(Setting::get('appliance.port'))->toBe('2002')
        ->and(file_get_contents($env))->toContain('APP_URL=https://vault.local:2002')
        ->and(file_get_contents($compose))->toContain('- 2002:443')
        ->and(file_get_contents($compose))->toContain('- 8081:80');

    Notification::assertNotified('Host & Port saved — restart required');

    unlink($env);
    unlink($compose);
});

it('rejects an empty hostname without writing settings', function () {
    Livewire::test(ManageSettings::class)
        ->set('data.appliance.host', '')
        ->set('data.appliance.port', '2002')
        ->call('saveHostPort');

    Notification::assertNotified('Hostname is required');
    expect(Setting::where('key', 'appliance.host')->exists())->toBeFalse();
});

it('rejects a hostname with injection characters without writing infra', function () {
    $env = tempnam(sys_get_temp_dir(), 'env_');
    file_put_contents($env, "APP_URL=https://old.example:8080\n");
    $compose = tempnam(sys_get_temp_dir(), 'compose_');
    file_put_contents($compose, "    nginx:\n        ports:\n          - 8080:443\n");
    app()->bind(ApplianceNetworkSettings::class, fn () => new ApplianceNetworkSettings($env, $compose));

    // A newline in the host would inject arbitrary .env lines if written raw.
    Livewire::test(ManageSettings::class)
        ->set('data.appliance.host', "vault.local\nAPP_DEBUG=true")
        ->set('data.appliance.port', '2002')
        ->call('saveHostPort');

    Notification::assertNotified('Invalid hostname');
    expect(Setting::where('key', 'appliance.host')->exists())->toBeFalse()
        ->and(file_get_contents($env))->not->toContain('APP_DEBUG=true')
        ->and(file_get_contents($env))->toContain('APP_URL=https://old.example:8080');

    unlink($env);
    unlink($compose);
});

it('rejects an out-of-range port', function () {
    Livewire::test(ManageSettings::class)
        ->set('data.appliance.host', 'vault.local')
        ->set('data.appliance.port', '70000')
        ->call('saveHostPort');

    Notification::assertNotified('Port must be between 1 and 65535');
    expect(Setting::where('key', 'appliance.port')->exists())->toBeFalse();
});
