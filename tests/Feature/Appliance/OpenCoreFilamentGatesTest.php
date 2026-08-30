<?php

/**
 * Open-core Filament canAccess gates for the user-visible surfaces:
 *   - GroupResource              (admin-managed team vaults)
 *   - SyseventResource           (Event Log)
 *   - CreateUser page            (multi-user creation)
 *   - AnnouncementResource       (broadcast announcements)
 *
 * Each must return false on unlicensed appliance, true on SaaS, true on
 * licensed appliance, and false again after the license expires.
 *
 * NOTE: ManageModules ("Software Updates") is intentionally NOT here — its page
 * is always reachable so the local AI model can be downloaded on unlicensed
 * installs; only its module install/update sections are license-gated (see
 * ManageModulesPageTest). Adding it back here would be wrong.
 *
 * Quick `canAccess()` probes — no Filament panel needed.
 */

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Sysevents\SyseventResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\LocalLicense;
use Illuminate\Support\Str;

function installActiveLicense(): LocalLicense
{
    return LocalLicense::create([
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
}

dataset('opencore_gated_classes', [
    'GroupResource' => [GroupResource::class],
    'SyseventResource' => [SyseventResource::class],
    'CreateUser' => [CreateUser::class],
    'AnnouncementResource' => [AnnouncementResource::class],
]);

it('hides the gated class on unlicensed appliance', function (string $class) {
    config(['product.type' => 'appliance']);

    expect($class::canAccess())->toBeFalse();
})->with('opencore_gated_classes');

it('shows the gated class on licensed appliance', function (string $class) {
    config(['product.type' => 'appliance']);
    installActiveLicense();

    expect($class::canAccess())->toBeTrue();
})->with('opencore_gated_classes');

it('shows the gated class on SaaS regardless of license rows', function (string $class) {
    config(['product.type' => 'saas']);

    expect($class::canAccess())->toBeTrue();
})->with('opencore_gated_classes');

it('hides the gated class after license expires', function (string $class) {
    config(['product.type' => 'appliance']);
    installActiveLicense();

    expect($class::canAccess())->toBeTrue();

    LocalLicense::query()->update(['expires_at' => now()->subDay()]);

    expect($class::canAccess())->toBeFalse();
})->with('opencore_gated_classes');
