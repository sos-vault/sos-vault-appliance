<?php

/**
 * Open-core gate helpers: applianceLicensed() / applianceUnlicensed().
 *
 * Both helpers must produce the right answer across the four states:
 *   1. SaaS build                       → both false
 *   2. Appliance, no license            → unlicensed true, licensed false
 *   3. Appliance, ACTIVE unexpired      → licensed true, unlicensed false
 *   4. Appliance, ACTIVE but expired    → unlicensed true (expired = unlicensed),
 *                                         licensed false
 *
 * State 4 is the recovery scenario: a previously-licensed appliance whose
 * license expires reverts to baseline; LocalLicense::current() returns null
 * because the expires_at filter excludes expired rows.
 */

use App\Models\LocalLicense;

it('returns false for both helpers on the SaaS build', function () {
    config(['product.type' => 'saas']);

    expect(applianceLicensed())->toBeFalse();
    expect(applianceUnlicensed())->toBeFalse();
});

it('returns unlicensed=true when appliance has no LocalLicense row', function () {
    config(['product.type' => 'appliance']);

    expect(applianceLicensed())->toBeFalse();
    expect(applianceUnlicensed())->toBeTrue();
});

it('returns licensed=true when appliance has an ACTIVE unexpired license', function () {
    config(['product.type' => 'appliance']);

    LocalLicense::create([
        'customer_id' => 'cust_test_1',
        'machine_tokens' => ['sha256:abc'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => 'fake-signed-blob',
        'issued_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
    ]);

    expect(applianceLicensed())->toBeTrue();
    expect(applianceUnlicensed())->toBeFalse();
});

it('reverts to unlicensed when the only ACTIVE license has expired', function () {
    config(['product.type' => 'appliance']);

    LocalLicense::create([
        'customer_id' => 'cust_test_2',
        'machine_tokens' => ['sha256:abc'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => 'fake-signed-blob',
        'issued_at' => now()->subYears(2),
        'expires_at' => now()->subDay(),
    ]);

    expect(applianceLicensed())->toBeFalse();
    expect(applianceUnlicensed())->toBeTrue();
});

it('returns unlicensed when the only row is REVOKED even if unexpired', function () {
    config(['product.type' => 'appliance']);

    LocalLicense::create([
        'customer_id' => 'cust_test_3',
        'machine_tokens' => ['sha256:abc'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'REVOKED',
        'signed_license' => 'fake-signed-blob',
        'issued_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
    ]);

    expect(applianceLicensed())->toBeFalse();
    expect(applianceUnlicensed())->toBeTrue();
});
