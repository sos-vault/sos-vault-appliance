<?php

/**
 * Sprint 5 Step D — appliance dashboard license widget.
 *
 * The widget must be hidden on the SaaS build and visible on appliance.
 * When no license is installed it renders a "NONE" placeholder; once
 * a license is in place it shows seats used/total and time-until-expiry.
 */

use App\Filament\Widgets\ApplianceLicenseWidget;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function applianceLicenseWidgetMakeLicense(int $seats, ?Carbon $expiresAt = null): LocalLicense
{
    return LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => $seats,
        'features' => ['srms', 'ai'],
        'status' => 'ACTIVE',
        'signed_license' => 'stub',
        'issued_at' => now(),
        'expires_at' => $expiresAt ?: now()->addYear(),
        'uploaded_by' => null,
    ]);
}

function applianceLicenseWidgetStats(): array
{
    $reflection = new ReflectionClass(ApplianceLicenseWidget::class);
    $method = $reflection->getMethod('getStats');
    $method->setAccessible(true);

    return $method->invoke(new ApplianceLicenseWidget);
}

it('is hidden on the SaaS build', function () {
    config(['product.type' => 'saas']);

    expect(ApplianceLicenseWidget::canView())->toBeFalse();
});

it('is visible on the appliance build', function () {
    config(['product.type' => 'appliance']);

    expect(ApplianceLicenseWidget::canView())->toBeTrue();
});

it('shows the open-core baseline callout when no license is installed', function () {
    config(['product.type' => 'appliance']);

    $stats = applianceLicenseWidgetStats();

    expect($stats)->toHaveCount(4);
    // First stat is the install-license CTA. Tied to /admin/manage-license,
    // colour-warning rather than colour-danger ("we still work, we're just
    // gated"), and not the 'ACTIVE' string the licensed state uses.
    $first = $stats[0];
    expect($first->getColor())->toBe('warning');
    expect($first->getUrl())->toBe('/admin/manage-license');
    expect($first->getValue())->not->toBe('ACTIVE');
});

it('reports seat usage and license status when a license is installed', function () {
    config(['product.type' => 'appliance']);
    applianceLicenseWidgetMakeLicense(seats: 5, expiresAt: now()->addDays(45));
    // Three accounts: the reserved admin seat + two billed users. One seat is
    // always reserved for the admin, so the seat stat reads "2 / 4" — not
    // "3 / 5" — on a 5-seat license.
    User::factory()->create();
    User::factory()->create();
    User::factory()->create();
    Group::create(['name' => 'Default Team', 'owner_id' => User::first()->id, 'max_members' => 5]);

    $stats = applianceLicenseWidgetStats();

    expect($stats)->toHaveCount(4)
        ->and($stats[0]->getValue())->toBe('ACTIVE')
        ->and($stats[1]->getValue())->toBe('2 / 4')
        ->and($stats[2]->getValue())->toBe('1');
});

it('does not count the admin against seats (only the admin present reads 0 / N)', function () {
    config(['product.type' => 'appliance']);
    // A 10-user license is stored as 11 seats (10 billed + the reserved
    // admin). With only the admin account present the badge must read
    // "0 / 10", not "1 / 11".
    applianceLicenseWidgetMakeLicense(seats: 11, expiresAt: now()->addDays(90));
    User::factory()->create();

    $stats = applianceLicenseWidgetStats();

    expect($stats[1]->getValue())->toBe('0 / 10');
});

it('flags expiry within 7 days as danger color', function () {
    config(['product.type' => 'appliance']);
    applianceLicenseWidgetMakeLicense(seats: 5, expiresAt: now()->addDays(3));

    $stats = applianceLicenseWidgetStats();

    // 4th stat is Expiry
    expect($stats[3]->getColor())->toBe('danger');
});
