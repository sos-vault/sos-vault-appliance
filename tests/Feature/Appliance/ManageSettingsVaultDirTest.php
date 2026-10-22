<?php

/**
 * "Main Vault" section of the Manage Settings page (appliance admin).
 *
 * The vault-directory control was relocated here from the standalone
 * DiskManager page. It is available on every appliance install — licensed or
 * not — and hidden entirely on the SaaS build. sos-vault stores its vaults in
 * a plain directory (default /vault); the path must be absolute.
 */

use App\Filament\Pages\ManageSettings;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Wave\Setting;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

function installLicenseForSettingsVault(): LocalLicense
{
    return LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => "-----BEGIN PGP SIGNED MESSAGE-----\n...stub...",
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);
}

it('renders the Main Vault section on an unlicensed appliance', function () {
    config(['product.type' => 'appliance']);

    Livewire::test(ManageSettings::class)
        ->assertSee('Main Vault')
        ->assertSee('vault_dir');
});

it('renders the Main Vault section on a licensed appliance', function () {
    config(['product.type' => 'appliance']);
    installLicenseForSettingsVault();

    Livewire::test(ManageSettings::class)
        ->assertSee('Main Vault')
        ->assertSee('vault_dir');
});

it('hides the Main Vault section on the SaaS build', function () {
    config(['product.type' => 'saas']);

    Livewire::test(ManageSettings::class)
        ->assertDontSee('Main Vault');
});

it('defaults the vault-dir field to /vault when no setting is present', function () {
    config(['product.type' => 'appliance']);

    Livewire::test(ManageSettings::class)
        ->assertSet('data.appliance.vault_dir', '/vault');
});

it('persists the vault directory to the settings table when saved', function () {
    config(['product.type' => 'appliance']);

    Livewire::test(ManageSettings::class)
        ->set('data.appliance.vault_dir', '/srv/sos-vault-data')
        ->call('saveVaultDir');

    expect(Setting::get('appliance.vault_dir'))->toBe('/srv/sos-vault-data');
});

it('rejects a non-absolute vault directory path', function () {
    config(['product.type' => 'appliance']);

    Livewire::test(ManageSettings::class)
        ->set('data.appliance.vault_dir', 'relative/path')
        ->call('saveVaultDir');

    expect(Setting::get('appliance.vault_dir'))->toBeNull();
});
