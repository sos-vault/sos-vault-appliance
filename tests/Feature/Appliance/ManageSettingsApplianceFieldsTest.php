<?php

/**
 * Appliance-specific behaviour of the "Manage Settings" fields:
 *
 *   - "Default Role Assigned at Registration" offers only "Team Member" on the
 *     appliance (the other roles are SaaS plan/billing tiers).
 *   - "Default Vault Size (MB)" defaults to 500.
 *   - "Appliance Status" reserves one seat for the admin and reports
 *     Seats / Admin / Users / Groups in user-facing terms.
 */

use App\Filament\Pages\ManageSettings;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

function installFieldsLicenseWithSeats(int $seats): LocalLicense
{
    return LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => $seats,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => "-----BEGIN PGP SIGNED MESSAGE-----\n...stub...",
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);
}

it('offers only "Team Member" as the default registration role on the appliance', function () {
    config(['product.type' => 'appliance']);

    expect(ManageSettings::defaultRoleOptions())->toBe(['Team Member' => 'Team Member']);
});

it('offers the full role list as the default registration role on SaaS', function () {
    config(['product.type' => 'saas']);

    $options = ManageSettings::defaultRoleOptions();

    expect(array_keys($options))
        ->toContain('Team Member')
        ->toContain('admin')
        ->toContain('Enterprise');
});

it('reserves the admin seat in the Appliance Status summary', function () {
    config(['product.type' => 'appliance']);
    installFieldsLicenseWithSeats(11); // a "10-user" license is raw seats=11

    // One admin (already created in beforeEach) + one regular user + two groups.
    User::factory()->create()->assignRole('Team Member');
    Group::create(['name' => 'Group A', 'max_members' => 5]);
    Group::create(['name' => 'Group B', 'max_members' => 3]);

    expect(ManageSettings::applianceStatusSummary())
        ->toBe('Seats: 10 • Admin: 1 • Users: 1 • Groups: 2');
});

it('shows "Seats: 10 • Admin: 1 • Users: 0 • Groups: 1" for an admin-only box', function () {
    config(['product.type' => 'appliance']);
    installFieldsLicenseWithSeats(11);
    Group::create(['name' => 'Default Team', 'max_members' => 10]);

    expect(ManageSettings::applianceStatusSummary())
        ->toBe('Seats: 10 • Admin: 1 • Users: 0 • Groups: 1');
});

it('defaults the Default Vault Size field to 500 MB on a licensed appliance', function () {
    config(['product.type' => 'appliance']);
    installFieldsLicenseWithSeats(11);

    Livewire::test(ManageSettings::class)
        ->assertSet('data.appliance.default_vault_size_mb', 500);
});
