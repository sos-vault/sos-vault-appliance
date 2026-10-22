<?php

/**
 * The appliance admin always gets a personal LUKS-encrypted vault — sosreports
 * are private and must be encrypted at rest. Provisioned on first admin login
 * (App\Listeners\initializeVault), regardless of licence: encryption of the
 * admin's own workspace is a baseline guarantee, not a paid feature.
 *
 * The personal vault is owner=admin, shared_status='PRIVATE', and must stay
 * independent of any group the admin owns (e.g. "Default Team") — guarding the
 * SaaS createVault() group-link behaviour we deliberately avoided by using the
 * dedicated VaultTools::createPersonalVault() path.
 *
 * Runs under APP_NOVAULTS=TRUE (phpunit.xml), so these assert the DB-level
 * behaviour without real cryptsetup, mirroring GroupCreateProvisionsVaultTest.
 */

use App\Filament\Resources\Users\UserResource;
use App\Listeners\initializeVault;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);
});

function loginAdmin(User $admin): void
{
    (new initializeVault)->handle(new Login('web', $admin, false));
}

function makeApplianceAdmin(): User
{
    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->syncRoles(['admin']);

    return $admin->fresh();
}

function installApplianceLicense(int $seats = 5): LocalLicense
{
    return LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => $seats,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => 'stub',
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);
}

it('provisions a personal PRIVATE vault for the admin on an unlicensed appliance', function () {
    $admin = makeApplianceAdmin();
    expect(applianceLicensed())->toBeFalse();

    loginAdmin($admin);

    $vault = Vault::where('owner', $admin->id)->first();
    expect($vault)->not->toBeNull()
        ->and($vault->shared_status)->toBe('PRIVATE')
        ->and((int) $vault->owner)->toBe($admin->id);
});

it('provisions a personal vault for the admin on a licensed appliance too', function () {
    installApplianceLicense();
    $admin = makeApplianceAdmin();

    loginAdmin($admin);

    expect(Vault::where('owner', $admin->id)->where('shared_status', 'PRIVATE')->exists())->toBeTrue();
});

it('keeps the admin personal vault independent of a group the admin owns', function () {
    $admin = makeApplianceAdmin();
    // Admin owns "Default Team" (as ApplianceAdminSeeder would create), but is
    // NOT a member of it (group_id stays null).
    $group = Group::create(['name' => 'Default Team', 'owner_id' => $admin->id, 'max_members' => 8]);

    loginAdmin($admin);

    $vault = Vault::where('owner', $admin->id)->first();
    expect($vault)->not->toBeNull()
        ->and($vault->shared_status)->toBe('PRIVATE');
    // The meaningful independence check: the group did NOT adopt the admin's
    // personal vault as its shared group vault.
    expect($group->fresh()->vault_id)->toBeNull();
});

it('is idempotent — a second admin login does not create a second vault', function () {
    $admin = makeApplianceAdmin();

    loginAdmin($admin);
    loginAdmin($admin);

    expect(Vault::where('owner', $admin->id)->count())->toBe(1);
});

it('emits an ADD_VAULT SUCCESS event attributed to the admin', function () {
    $admin = makeApplianceAdmin();

    loginAdmin($admin);

    $vault = Vault::where('owner', $admin->id)->firstOrFail();
    $event = Sysevent::where('type', 'ADD_VAULT')->where('status', 'SUCCESS')->first();
    expect($event)->not->toBeNull()
        ->and((int) $event->vault_id)->toBe($vault->id)
        ->and((int) $event->owner)->toBe($admin->id);
});

it('does not give non-admin appliance members a personal vault on login', function () {
    installApplianceLicense();
    $member = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $member->syncRoles(['Team Member']);

    loginAdmin($member->fresh()); // reuse the login helper for the member

    expect(Vault::where('owner', $member->id)->exists())->toBeFalse();
});

it('exposes personal vault actions for the admin row but not for members on appliance', function () {
    installApplianceLicense();
    $admin = makeApplianceAdmin();
    $member = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $member->syncRoles(['Team Member']);

    expect(UserResource::personalVaultManageable($admin))->toBeTrue();
    expect(UserResource::personalVaultManageable($member->fresh()))->toBeFalse();
});

it('exposes personal vault actions for every user on SaaS', function () {
    config(['product.type' => 'saas']);
    $user = User::factory()->create();

    expect(UserResource::personalVaultManageable($user))->toBeTrue();
});
