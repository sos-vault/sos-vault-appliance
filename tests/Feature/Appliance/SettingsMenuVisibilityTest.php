<?php

/**
 * The app-side Settings sidebar (resources/themes/anchor/components/app/settings-layout)
 * gates two links on the appliance:
 *
 *   - ITSM Integration — a licensed feature: hidden on an unlicensed appliance,
 *     shown once a license is installed (and always on SaaS for entitled users).
 *   - Team Members — self-service team management is a SaaS concept; hidden on
 *     the appliance regardless of license (teams are managed admin-side there).
 */

use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function makeSettingsAdmin(): User
{
    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->syncRoles(['admin']);
    // checkAccess() reads the wave single-role (role_id); admin short-circuits to true.
    $admin->role_id = Role::where('name', 'admin')->value('id');
    $admin->save();
    // isTeamManager() is true when the user owns a group.
    Group::create(['name' => 'Default Team', 'owner_id' => $admin->id, 'max_members' => 5]);

    return $admin->fresh();
}

function installSettingsLicense(): void
{
    LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => 'stub',
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);
}

it('hides the ITSM link on an unlicensed appliance', function () {
    config(['product.type' => 'appliance']);

    $this->actingAs(makeSettingsAdmin())
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertDontSee(route('settings.itsm'));
});

it('shows the ITSM link on a licensed appliance', function () {
    config(['product.type' => 'appliance']);
    installSettingsLicense();

    $this->actingAs(makeSettingsAdmin())
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertSee(route('settings.itsm'));
});

it('hides the Team Members link on the appliance even when licensed', function () {
    config(['product.type' => 'appliance']);
    installSettingsLicense();

    $this->actingAs(makeSettingsAdmin())
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertDontSee(route('settings.team'));
});

it('shows the ITSM and Team Members links on SaaS for an entitled team manager', function () {
    config(['product.type' => 'saas']);

    $this->actingAs(makeSettingsAdmin())
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertSee(route('settings.itsm'))
        ->assertSee(route('settings.team'));
});
