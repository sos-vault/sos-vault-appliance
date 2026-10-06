<?php

/**
 * Open-core single-admin baseline on the unlicensed appliance.
 *
 * The legacy behaviour ("appliance without license refuses ALL users") is
 * replaced by: ONE user is allowed unlicensed (the operator/admin planted by
 * ApplianceAdminSeeder); the second creation attempt fails. With a license,
 * SeatLimitTest covers the licensed cap.
 *
 * The SaaS branch is unaffected — User::creating() short-circuits for
 * isSaas(), which is the global beforeEach default in tests/Pest.php.
 */

use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

it('permits the first user when appliance has no license (single admin baseline)', function () {
    config(['product.type' => 'appliance']);

    expect(User::count())->toBe(0);

    $first = User::factory()->create();

    expect(User::count())->toBe(1);
    expect($first->exists)->toBeTrue();
});

it('refuses the second user when appliance has no license', function () {
    config(['product.type' => 'appliance']);

    User::factory()->create();

    expect(fn () => User::factory()->create())
        ->toThrow(RuntimeException::class, 'Open-core baseline allows a single admin user');

    expect(User::count())->toBe(1);
});

it('relaxes the single-admin cap once a license is installed', function () {
    config(['product.type' => 'appliance']);

    User::factory()->create();

    LocalLicense::create([
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

    User::factory()->create();
    User::factory()->create();

    expect(User::count())->toBe(3);
});

it('refuses the second user once license has expired (reverts to baseline)', function () {
    config(['product.type' => 'appliance']);

    // Pre-existing licensed users.
    LocalLicense::create([
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
    User::factory()->create();
    User::factory()->create();

    // Expire the license.
    LocalLicense::query()->update(['expires_at' => now()->subDay()]);

    expect(fn () => User::factory()->create())
        ->toThrow(RuntimeException::class, 'Open-core baseline allows a single admin user');
});

it('still does not enforce any limit on the SaaS build', function () {
    config(['product.type' => 'saas']);

    User::factory()->create();
    User::factory()->create();
    User::factory()->create();

    expect(User::count())->toBe(3);
});
