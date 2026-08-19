<?php

/**
 * Installed-license card (appliance Manage License page) — seat accounting.
 *
 * The card must present seats in user-facing terms, reserving one seat for
 * the always-included admin: a 10-user license is stored as 11 seats, so with
 * only the admin present the card reads "0 / 10 used · 10 remaining", matching
 * the dashboard widget — not "1 / 11 used · 10 remaining".
 */

use App\Filament\Pages\ManageLicense;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);
});

function manageLicenseSeatsMakeLicense(int $seats): LocalLicense
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

it('does not count the admin against seats on the installed-license card', function () {
    manageLicenseSeatsMakeLicense(seats: 11);
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $info = (new ManageLicense)->getInstalledLicenseData();

    expect($info['installed'])->toBeTrue()
        ->and($info['seats'])->toBe(10)
        ->and($info['seats_used'])->toBe(0)
        ->and($info['seats_remaining'])->toBe(10);
});

it('counts only non-admin users against the user-facing seats', function () {
    manageLicenseSeatsMakeLicense(seats: 11);
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);
    User::factory()->count(3)->create();

    $info = (new ManageLicense)->getInstalledLicenseData();

    expect($info['seats'])->toBe(10)
        ->and($info['seats_used'])->toBe(3)
        ->and($info['seats_remaining'])->toBe(7);
});
