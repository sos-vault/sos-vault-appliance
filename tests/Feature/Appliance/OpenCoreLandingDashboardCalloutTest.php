<?php

/**
 * ApplianceLicenseWidget unlicensed-state callout: the operator landing on
 * /admin must see a clear "install a license to unlock" call-to-action that
 * links to /admin/manage-license. The widget rerenders normally once a
 * license is installed.
 */

use App\Filament\Widgets\ApplianceLicenseWidget;
use App\Models\LocalLicense;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function renderApplianceLicenseStats(): array
{
    $widget = new ApplianceLicenseWidget;
    $ref = new ReflectionMethod($widget, 'getStats');
    $ref->setAccessible(true);

    return $ref->invoke($widget);
}

it('renders the upgrade callout on unlicensed appliance', function () {
    config(['product.type' => 'appliance']);

    $stats = renderApplianceLicenseStats();
    $first = $stats[0];

    expect($first->getUrl())->toBe('/admin/manage-license');
    expect($first->getColor())->toBe('warning');
});

it('drops the upgrade callout once a license is installed', function () {
    config(['product.type' => 'appliance']);

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

    $stats = renderApplianceLicenseStats();
    $first = $stats[0];

    expect($first->getValue())->toBe('ACTIVE');
    // The license-stat URL goes back to null once a license is installed
    // (there's nothing to invite the operator to do).
    expect($first->getUrl())->toBeNull();
});

it('does not render the widget at all on SaaS', function () {
    config(['product.type' => 'saas']);

    expect(ApplianceLicenseWidget::canView())->toBeFalse();
});

it('returns to the upgrade callout after a license expires', function () {
    config(['product.type' => 'appliance']);

    $license = LocalLicense::create([
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

    $statsBefore = renderApplianceLicenseStats();
    expect($statsBefore[0]->getUrl())->toBeNull();

    $license->update(['expires_at' => now()->subDay()]);

    $statsAfter = renderApplianceLicenseStats();
    expect($statsAfter[0]->getUrl())->toBe('/admin/manage-license');
    expect($statsAfter[0]->getColor())->toBe('warning');
});
