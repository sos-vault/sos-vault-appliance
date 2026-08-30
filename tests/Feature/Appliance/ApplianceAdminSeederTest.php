<?php

/**
 * Sprint 6 Step A — first-boot admin user + default team seeder.
 *
 * Database\Seeders\ApplianceAdminSeeder is invoked by the installer after
 * `php artisan migrate`. It bypasses User::creating()'s seat guard (no
 * LocalLicense is installed yet at first boot), assigns the admin role,
 * and creates the Default Team if none exists. Idempotent on re-run.
 */

use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\ApplianceAdminSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Wave\Setting;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    foreach (['INSTALLER_ADMIN_EMAIL', 'INSTALLER_ADMIN_PASSWORD', 'INSTALLER_ADMIN_NAME', 'INSTALLER_ADMIN_USERNAME'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
});

afterEach(function () {
    foreach (['INSTALLER_ADMIN_EMAIL', 'INSTALLER_ADMIN_PASSWORD', 'INSTALLER_ADMIN_NAME', 'INSTALLER_ADMIN_USERNAME'] as $key) {
        putenv($key);
    }
});

function setInstallerEnv(array $vars): void
{
    foreach ($vars as $k => $v) {
        putenv("$k=$v");
    }
}

function installApplianceLicenseWithSeats(int $seats): LocalLicense
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

it('creates an admin user with hashed password on appliance', function () {
    config(['product.type' => 'appliance']);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'CorrectHorseBatteryStaple',
        'INSTALLER_ADMIN_NAME' => 'Site Operator',
    ]);

    (new ApplianceAdminSeeder)->run();

    $user = User::query()->where('email', 'ops@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Site Operator')
        ->and($user->username)->toBe('ops')
        ->and($user->verified)->toBe(1)
        ->and(Hash::check('CorrectHorseBatteryStaple', $user->password))->toBeTrue();
});

it('attaches the admin role even when the roles table starts empty', function () {
    // Real first-boot condition: the deb ships a clean, empty DB so the spatie
    // roles table has no rows. The seeder must self-seed the canonical roles
    // (via RolesTableSeeder) before syncRoles('admin'), or it throws
    // RoleDoesNotExist. (The beforeEach pre-seeds roles, so clear them first.)
    config(['product.type' => 'appliance']);
    Role::query()->delete();
    expect(Role::count())->toBe(0);

    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();

    $user = User::query()->where('email', 'ops@example.com')->firstOrFail();
    expect($user->hasRole('admin'))->toBeTrue()
        ->and(Role::where('name', 'admin')->where('guard_name', 'web')->exists())->toBeTrue();
});

it('seeds the active anchor theme so the theme:: namespace resolves', function () {
    // Real first-boot condition: an empty themes table means DevDojo\Themes
    // never registers the `theme::` view namespace and the login page throws
    // "No hint path defined for [theme]". The seeder must seed the active theme.
    config(['product.type' => 'appliance']);
    DB::table('themes')->delete();
    expect(DB::table('themes')->count())->toBe(0);

    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();

    expect(DB::table('themes')->where('active', 1)->where('folder', 'anchor')->exists())->toBeTrue();
});

it('populates the changelog with the sos-vault release notes', function () {
    // Users can view /changelog on the appliance, so the table must be seeded
    // with the product (not Wave boilerplate) entries.
    config(['product.type' => 'appliance']);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();

    expect(DB::table('changelogs')->where('title', 'sos-vault 1.0.0 Released')->exists())->toBeTrue()
        ->and(DB::table('changelogs')->whereRaw("title LIKE 'Wave %'")->exists())->toBeFalse();
});

it('seeds appliance.host and appliance.port from APP_URL', function () {
    config(['product.type' => 'appliance', 'app.url' => 'https://vault.acme.internal:8443']);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();

    expect(Setting::where('key', 'appliance.host')->value('value'))->toBe('vault.acme.internal')
        ->and(Setting::where('key', 'appliance.port')->value('value'))->toBe('8443');
});

it('does not clobber an operator-edited host/port on re-seed', function () {
    config(['product.type' => 'appliance', 'app.url' => 'https://vault.acme.internal:8443']);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();
    Setting::where('key', 'appliance.port')->update(['value' => '9999']);
    (new ApplianceAdminSeeder)->run();

    expect(Setting::where('key', 'appliance.port')->value('value'))->toBe('9999');
});

it('attaches the admin role to the seeded user', function () {
    config(['product.type' => 'appliance']);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();

    $user = User::query()->where('email', 'ops@example.com')->firstOrFail();
    expect($user->hasRole('admin'))->toBeTrue();
});

it('bypasses the User::creating() seat guard when no license is installed', function () {
    // This is the load-bearing case: on first boot the operator hasn't
    // uploaded a .lic yet, so User::factory()->create() would throw. The
    // seeder must still succeed.
    config(['product.type' => 'appliance']);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    expect(LocalLicense::current())->toBeNull();

    (new ApplianceAdminSeeder)->run();

    expect(User::query()->where('email', 'ops@example.com')->exists())->toBeTrue();
});

it('creates the Default Team owned by the new admin', function () {
    config(['product.type' => 'appliance']);
    installApplianceLicenseWithSeats(5);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    expect(Group::count())->toBe(0);

    (new ApplianceAdminSeeder)->run();

    $user = User::query()->where('email', 'ops@example.com')->firstOrFail();
    $team = Group::first();
    expect($team)->not->toBeNull()
        ->and($team->name)->toBe('Default Team')
        ->and($team->owner_id)->toBe($user->id)
        ->and($team->max_members)->toBe(5);
});

it('falls back to max_members=8 when no license is installed', function () {
    config(['product.type' => 'appliance']);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();

    expect(Group::first()->max_members)->toBe(8);
});

it('is idempotent when re-run with the same email', function () {
    config(['product.type' => 'appliance']);
    installApplianceLicenseWithSeats(5);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();
    (new ApplianceAdminSeeder)->run();

    expect(User::where('email', 'ops@example.com')->count())->toBe(1)
        ->and(Group::count())->toBe(1);
});

it('throws when INSTALLER_ADMIN_EMAIL is missing', function () {
    config(['product.type' => 'appliance']);
    setInstallerEnv(['INSTALLER_ADMIN_PASSWORD' => 'pw']);

    expect(fn () => (new ApplianceAdminSeeder)->run())
        ->toThrow(RuntimeException::class, 'INSTALLER_ADMIN_EMAIL');
});

it('throws when INSTALLER_ADMIN_PASSWORD is missing', function () {
    config(['product.type' => 'appliance']);
    setInstallerEnv(['INSTALLER_ADMIN_EMAIL' => 'ops@example.com']);

    expect(fn () => (new ApplianceAdminSeeder)->run())
        ->toThrow(RuntimeException::class, 'INSTALLER_ADMIN_PASSWORD');
});

it('refuses to run on the saas build', function () {
    config(['product.type' => 'saas']);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    expect(fn () => (new ApplianceAdminSeeder)->run())
        ->toThrow(RuntimeException::class, 'appliance');
});

it('disambiguates username collisions', function () {
    config(['product.type' => 'appliance']);
    installApplianceLicenseWithSeats(5);

    // A pre-existing user with the same slugified username.
    User::factory()->create(['username' => 'ops']);

    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
    ]);

    (new ApplianceAdminSeeder)->run();

    $user = User::query()->where('email', 'ops@example.com')->firstOrFail();
    expect($user->username)->toBe('ops1');
});

it('honours an explicit INSTALLER_ADMIN_USERNAME override', function () {
    config(['product.type' => 'appliance']);
    installApplianceLicenseWithSeats(5);
    setInstallerEnv([
        'INSTALLER_ADMIN_EMAIL' => 'ops@example.com',
        'INSTALLER_ADMIN_PASSWORD' => 'pw',
        'INSTALLER_ADMIN_USERNAME' => 'sysop',
    ]);

    (new ApplianceAdminSeeder)->run();

    $user = User::query()->where('email', 'ops@example.com')->firstOrFail();
    expect($user->username)->toBe('sysop');
});
