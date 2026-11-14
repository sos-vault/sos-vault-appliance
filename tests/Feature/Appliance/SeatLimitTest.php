<?php

/**
 * Sprint 5 / PHASE 6 Step B — seat-limit enforcement at user creation.
 *
 * On the appliance build, User::creating() enforces:
 *   - no LocalLicense + zero users: ALLOWED (open-core single-admin baseline)
 *   - no LocalLicense + ≥1 existing user: refused (use a license to add more)
 *   - LocalLicense + users >= license.seats: refused
 *
 * The SaaS build is unaffected — license rows are not consulted.
 *
 * Single-admin baseline behaviour has dedicated coverage in
 * OpenCoreUserCreatingTest; this file remains focused on the seat-cap
 * (licensed) path.
 */

use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function installLicenseWithSeats(int $seats): LocalLicense
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

it('refuses the second user when product.type is appliance and no license is installed', function () {
    config(['product.type' => 'appliance']);

    // Open-core baseline: the first user (admin) is permitted unlicensed.
    User::factory()->create();

    expect(fn () => User::factory()->create())
        ->toThrow(RuntimeException::class, 'Open-core baseline allows a single admin user');
});

it('allows user creation up to the licensed seat count on appliance', function () {
    config(['product.type' => 'appliance']);
    installLicenseWithSeats(3);

    User::factory()->create();
    User::factory()->create();
    User::factory()->create();

    expect(User::count())->toBe(3);
});

it('refuses the (n+1)th user when the license caps seats at n', function () {
    config(['product.type' => 'appliance']);
    installLicenseWithSeats(2);

    User::factory()->create();
    User::factory()->create();

    expect(fn () => User::factory()->create())
        ->toThrow(RuntimeException::class, 'Seat limit reached');

    expect(User::count())->toBe(2);
});

it('does not enforce seat limits on the saas build even with no license installed', function () {
    config(['product.type' => 'saas']);

    User::factory()->create();
    User::factory()->create();

    expect(User::count())->toBe(2);
});
