<?php

/**
 * Sprint 5 Step D — default-team migration.
 *
 * The migration `2026_04_29_160741_seed_default_team_on_appliance.php`
 * creates a Group on appliance installs when (a) at least one User
 * exists, and (b) no Group has been created yet. It is a no-op on the
 * SaaS branch and on appliance hosts that already have a team.
 */

use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function migrationPath(): string
{
    return database_path('migrations/2026_04_29_160741_seed_default_team_on_appliance.php');
}

function loadDefaultTeamMigration(): object
{
    return require migrationPath();
}

function installLicenseSeats(int $seats): LocalLicense
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

it('seeds a default team on appliance when a user exists and no group does', function () {
    config(['product.type' => 'appliance']);
    installLicenseSeats(5);
    $admin = User::factory()->create();

    Group::query()->delete();
    expect(Group::count())->toBe(0);

    loadDefaultTeamMigration()->up();

    $team = Group::first();
    expect($team)->not->toBeNull()
        ->and($team->name)->toBe('Default Team')
        ->and($team->owner_id)->toBe($admin->id)
        ->and($team->max_members)->toBe(5);
});

it('does nothing on the saas build even with users present', function () {
    config(['product.type' => 'saas']);
    User::factory()->create();
    Group::query()->delete();

    loadDefaultTeamMigration()->up();

    expect(Group::count())->toBe(0);
});

it('is a no-op when a group already exists on appliance', function () {
    config(['product.type' => 'appliance']);
    installLicenseSeats(5);
    $owner = User::factory()->create();

    Group::create([
        'name' => 'Existing Team',
        'owner_id' => $owner->id,
        'max_members' => 3,
    ]);

    loadDefaultTeamMigration()->up();

    expect(Group::count())->toBe(1)
        ->and(Group::first()->name)->toBe('Existing Team');
});

it('is a no-op on appliance when no users exist yet', function () {
    config(['product.type' => 'appliance']);
    Group::query()->delete();
    User::query()->delete();

    loadDefaultTeamMigration()->up();

    expect(Group::count())->toBe(0);
});

it('falls back to max_members=8 when no license is installed', function () {
    // Only reachable if the seat-limit guard is bypassed (e.g., user
    // pre-existed the migration with no license uploaded yet).
    config(['product.type' => 'saas']);
    $owner = User::factory()->create();
    Group::query()->delete();

    config(['product.type' => 'appliance']);
    LocalLicense::query()->delete();

    loadDefaultTeamMigration()->up();

    $team = Group::first();
    expect($team)->not->toBeNull()
        ->and($team->max_members)->toBe(8);
});
