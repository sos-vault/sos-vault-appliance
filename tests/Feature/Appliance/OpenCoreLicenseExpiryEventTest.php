<?php

/**
 * sos-vault:check-license-expiry — emit a single LICENSE_EXPIRED event per
 * newly-expired LocalLicense. The expiry_event_logged_at column makes the
 * command idempotent across daily runs.
 */

use App\Models\LocalLicense;
use App\Models\Sysevent;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    config(['product.type' => 'appliance']);
});

function makeExpiredLicense(): LocalLicense
{
    return LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => "-----BEGIN PGP SIGNED MESSAGE-----\n...stub...",
        'issued_at' => now()->subYear(),
        'expires_at' => now()->subDay(),
        'uploaded_by' => null,
    ]);
}

it('emits a LICENSE_EXPIRED event for a freshly-expired license', function () {
    $license = makeExpiredLicense();

    expect(Sysevent::query()->where('type', 'LICENSE_EXPIRED')->count())->toBe(0);

    $this->artisan('sos-vault:check-license-expiry')->assertExitCode(0);

    expect(Sysevent::query()->where('type', 'LICENSE_EXPIRED')->count())->toBe(1);
    expect($license->fresh()->expiry_event_logged_at)->not->toBeNull();
});

it('does not re-emit the event on a second run', function () {
    makeExpiredLicense();

    $this->artisan('sos-vault:check-license-expiry')->assertExitCode(0);
    $this->artisan('sos-vault:check-license-expiry')->assertExitCode(0);

    expect(Sysevent::query()->where('type', 'LICENSE_EXPIRED')->count())->toBe(1);
});

it('does nothing when no licenses are expired', function () {
    LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => "-----BEGIN PGP SIGNED MESSAGE-----\n...stub...",
        'issued_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);

    $this->artisan('sos-vault:check-license-expiry')->assertExitCode(0);

    expect(Sysevent::query()->where('type', 'LICENSE_EXPIRED')->count())->toBe(0);
});

it('skips on SaaS builds', function () {
    config(['product.type' => 'saas']);
    makeExpiredLicense();

    $this->artisan('sos-vault:check-license-expiry')->assertExitCode(0);

    expect(Sysevent::query()->where('type', 'LICENSE_EXPIRED')->count())->toBe(0);
});

it('emits one event per newly-expired license in a batch run', function () {
    makeExpiredLicense();
    makeExpiredLicense();
    makeExpiredLicense();

    $this->artisan('sos-vault:check-license-expiry')->assertExitCode(0);

    expect(Sysevent::query()->where('type', 'LICENSE_EXPIRED')->count())->toBe(3);
});
