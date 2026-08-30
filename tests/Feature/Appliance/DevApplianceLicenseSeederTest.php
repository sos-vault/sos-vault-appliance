<?php

/**
 * Dev-only license seeder guard rails. Installs an unsigned, fully-featured
 * LocalLicense for local testing of the licensed appliance surface, but must
 * refuse to run on SaaS or on production, and must be idempotent.
 */

use App\Models\LocalLicense;
use Database\Seeders\DevApplianceLicenseSeeder;

it('installs a fully-featured dev license on a non-production appliance', function () {
    config(['product.type' => 'appliance']);

    (new DevApplianceLicenseSeeder)->run();

    $license = LocalLicense::current();
    expect($license)->not->toBeNull();
    expect($license->seats)->toBe(25);
    expect($license->features)->toContain('srms');
    expect($license->isActive())->toBeTrue();
});

it('is idempotent when an active license already exists', function () {
    config(['product.type' => 'appliance']);

    (new DevApplianceLicenseSeeder)->run();
    (new DevApplianceLicenseSeeder)->run();

    expect(LocalLicense::count())->toBe(1);
});

it('refuses to run on the saas build', function () {
    config(['product.type' => 'saas']);

    expect(fn () => (new DevApplianceLicenseSeeder)->run())
        ->toThrow(RuntimeException::class, 'only runs on appliance');

    expect(LocalLicense::count())->toBe(0);
});

it('refuses to run on the production environment', function () {
    config(['product.type' => 'appliance']);
    app()['env'] = 'production';

    expect(fn () => (new DevApplianceLicenseSeeder)->run())
        ->toThrow(RuntimeException::class, 'never run on production');

    expect(LocalLicense::count())->toBe(0);
});
