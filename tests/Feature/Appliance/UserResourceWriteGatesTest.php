<?php

/**
 * Open-core write-operation gates on UserResource.
 *
 * The Users LIST stays visible on every appliance state so the admin can see
 * existing accounts, but create / edit / delete / suspend are gated to SaaS or
 * an actively-licensed appliance (UserResource::canManageUsers()).
 *
 * Key requirement: when a licensed appliance with many users has its license
 * EXPIRE, all users must remain SHOWN but become read-only — cannot be created,
 * edited, changed, or deleted. LocalLicense::current() excludes expired rows,
 * so the expired state collapses into "not licensed".
 *
 * canCreate() also fixes the original button leak: CreateUser::canAccess()
 * guarded only the route, leaving the "New user" header button (driven by
 * canCreate()) rendering on an unlicensed appliance and dead-ending at a 403.
 */

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function seedActiveApplianceLicense(int $seats = 5): LocalLicense
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

it('shows the Users page to the admin on a fresh single-admin unlicensed appliance', function () {
    config(['product.type' => 'appliance']);

    // Open-core baseline: exactly one admin is allowed without a license.
    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->syncRoles(['admin']);

    // The admin always needs the page — it hosts the per-row vault actions that
    // are their only way to resize their own LUKS vault.
    $this->actingAs($admin);
    expect(UserResource::canViewAny())->toBeTrue();

    // ...but management stays gated until a license is installed.
    expect(UserResource::canCreate())->toBeFalse();      // no "New user" button
    expect(UserResource::canEdit($admin))->toBeFalse();  // read-only
    expect(UserResource::canDelete($admin))->toBeFalse();
    expect(UserResource::canDeleteAny())->toBeFalse();
});

it('allows full user management on a licensed appliance', function () {
    config(['product.type' => 'appliance']);
    seedActiveApplianceLicense();

    $user = User::factory()->create();

    expect(UserResource::canViewAny())->toBeTrue();
    expect(UserResource::canCreate())->toBeTrue();
    expect(UserResource::canEdit($user))->toBeTrue();
    expect(UserResource::canDelete($user))->toBeTrue();
    expect(UserResource::canDeleteAny())->toBeTrue();
});

it('shows all users read-only after the license expires', function () {
    config(['product.type' => 'appliance']);
    seedActiveApplianceLicense(seats: 5);

    // Create several users while the license is active.
    $users = User::factory()->count(3)->create();

    // The license lapses.
    LocalLicense::query()->update(['expires_at' => now()->subDay()]);
    expect(LocalLicense::current())->toBeNull();

    // All rows remain present and the list is still viewable...
    expect(User::count())->toBe(3);
    expect(UserResource::canViewAny())->toBeTrue();

    // ...but nothing can be created, edited, changed, or deleted.
    expect(UserResource::canCreate())->toBeFalse();
    expect(UserResource::canDeleteAny())->toBeFalse();
    foreach ($users as $user) {
        expect(UserResource::canEdit($user))->toBeFalse();
        expect(UserResource::canDelete($user))->toBeFalse();
    }
});

it('hides the "New user" header button after the license expires', function () {
    config(['product.type' => 'appliance']);
    seedActiveApplianceLicense();

    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->syncRoles(['admin']);
    // Non-admin accounts keep the read-only list reachable post-expiry.
    User::factory()->count(2)->create();

    LocalLicense::query()->update(['expires_at' => now()->subDay()]);

    Livewire::actingAs($admin);
    Livewire::test(ListUsers::class)
        ->assertActionHidden('create');
});

it('shows the "New user" header button on a licensed appliance', function () {
    config(['product.type' => 'appliance']);
    seedActiveApplianceLicense();

    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->syncRoles(['admin']);

    Livewire::actingAs($admin);
    Livewire::test(ListUsers::class)
        ->assertActionVisible('create');
});

it('allows full user management on SaaS regardless of license rows', function () {
    config(['product.type' => 'saas']);

    $user = User::factory()->create();

    expect(UserResource::canCreate())->toBeTrue();
    expect(UserResource::canEdit($user))->toBeTrue();
    expect(UserResource::canDelete($user))->toBeTrue();
    expect(UserResource::canDeleteAny())->toBeTrue();
});
