<?php

/**
 * Presentation rules for the appliance vs SaaS:
 *
 *   - Changelog page (product release notes) is viewable on BOTH builds, but the
 *     admin Filament resource is read-only on the appliance (no create/edit/
 *     delete — the customer must not rewrite shipped release notes).
 *   - Dashboard billing widgets (subscription / invoices) — not rendered on the
 *     appliance.
 *   - Storage URLs — root-relative so images resolve same-origin regardless of
 *     the host the user connected on.
 *   - Login overlay upgrades its message while a vault is being provisioned.
 */

use App\Filament\Resources\Changelogs\ChangelogResource;
use Illuminate\Support\Facades\Storage;
use Wave\Changelog;

it('serves the changelog page on the appliance', function () {
    config(['product.type' => 'appliance']);

    $this->get('/changelog')->assertOk();
});

it('serves the changelog page on SaaS', function () {
    config(['product.type' => 'saas']);

    $this->get('/changelog')->assertOk();
});

it('hides the admin changelog resource entirely on the appliance', function () {
    config(['product.type' => 'appliance']);
    $log = Changelog::create(['title' => 't', 'description' => 'd', 'body' => '<p>b</p>']);

    // canViewAny() gates the nav item (via canAccess()) and page access.
    expect(ChangelogResource::canViewAny())->toBeFalse()
        ->and(ChangelogResource::canAccess())->toBeFalse()
        ->and(ChangelogResource::canCreate())->toBeFalse()
        ->and(ChangelogResource::canEdit($log))->toBeFalse()
        ->and(ChangelogResource::canDelete($log))->toBeFalse()
        ->and(ChangelogResource::canDeleteAny())->toBeFalse();
});

it('allows full changelog management on SaaS', function () {
    config(['product.type' => 'saas']);
    $log = Changelog::create(['title' => 't', 'description' => 'd', 'body' => '<p>b</p>']);

    expect(ChangelogResource::canViewAny())->toBeTrue()
        ->and(ChangelogResource::canCreate())->toBeTrue()
        ->and(ChangelogResource::canEdit($log))->toBeTrue()
        ->and(ChangelogResource::canDelete($log))->toBeTrue()
        ->and(ChangelogResource::canDeleteAny())->toBeTrue();
});

it('keeps the changelog sidebar link visible (no appliance gate)', function () {
    $sidebar = file_get_contents(base_path('resources/themes/anchor/components/app/sidebar.blade.php'));

    expect($sidebar)->toContain("route('changelogs')")
        ->and($sidebar)->not->toContain('@unless(isAppliance())');
});

it('gates the dashboard subscription and invoice widgets on the appliance', function () {
    $blade = file_get_contents(base_path('resources/themes/anchor/pages/dashboard/index.blade.php'));

    // Both billing widgets drop out when isAppliance() (or a group member).
    expect(substr_count($blade, '($isGroupMember || isAppliance()) ? null'))->toBe(2);
});

it('404s the pricing page on the appliance', function () {
    config(['product.type' => 'appliance']);

    $this->get('/pricing')->assertNotFound();
});

it('serves the pricing page on SaaS', function () {
    config(['product.type' => 'saas']);

    $this->get('/pricing')->assertOk();
});

it('404s the guest checkout return on the appliance', function () {
    config(['product.type' => 'appliance']);

    // DenyOnAppliance runs before the controller — the SaaS-only checkout return
    // is unreachable on a box (billing happens on sos-vault.com).
    $this->post('/checkout/complete')->assertNotFound();
});

it('reaches the guest checkout controller on SaaS (not 404)', function () {
    config(['product.type' => 'saas']);

    // The route is live (not gated): an empty payload reaches the controller,
    // which returns 200 with a "missing transaction" JSON body — proving it is
    // NOT 404'd by DenyOnAppliance.
    $this->post('/checkout/complete')
        ->assertOk()
        ->assertJson(['status' => 0, 'message' => 'Missing transaction ID.']);
});

it('generates root-relative public storage URLs', function () {
    expect(config('filesystems.disks.public.url'))->toBe('/storage')
        ->and(Storage::disk('public')->url('posts/October2024/x.png'))
        ->toBe('/storage/posts/October2024/x.png');
});

it('uses relative /storage paths for images embedded in seeded post bodies', function () {
    $json = file_get_contents(base_path('database/seeders/data/appliance-docs.json'));

    // No absolute dev-box image hosts leak into the rendered HTML bodies.
    expect($json)->not->toContain('sos-vault.com:8080/storage')
        ->and($json)->toContain('/storage/posts/');
});

it('shows a plain "Welcome" login header (not "Welcome back")', function () {
    $login = file_get_contents(base_path('resources/themes/anchor/auth/login.blade.php'));

    expect($login)->toContain('Welcome')
        ->and($login)->not->toContain('Welcome back');
});

it('upgrades the login overlay copy while a vault is being created', function () {
    $login = file_get_contents(base_path('resources/themes/anchor/auth/login.blade.php'));

    expect($login)
        ->toContain('loginProgressTitle')
        ->toContain('loginProgressSubtext')
        ->toContain('this will take a few minutes')
        ->toContain('This will take a few seconds');
});

it('waits 11s before upgrading the overlay so a normal vault open never sees it', function () {
    $login = file_get_contents(base_path('resources/themes/anchor/auth/login.blade.php'));

    // The "Initializing your vault … a few minutes" copy must only appear on a
    // genuine provisioning run. The delay was lengthened from 8s to 11s so a
    // normal (fast) vault open redirects and unloads the page first.
    expect($login)
        ->toContain('}, 11000);')
        ->not->toContain('}, 8000);');
});

it('hides the "Total Subscribers" badge on the appliance dashboard', function () {
    $widget = file_get_contents(base_path('wave/resources/views/widgets/users-widget.blade.php'));

    // "Total Subscribers" is a SaaS billing metric — the badge must sit behind
    // an isAppliance() gate so only "Active User Accounts" shows on a box.
    expect($widget)
        ->toContain('Total Subscribers')
        ->toContain('@unless(isAppliance())')
        ->toContain('@endunless');
});

it('does not register the Google Analytics placeholder widget on the appliance', function () {
    $provider = file_get_contents(base_path('app/Providers/Filament/AdminPanelProvider.php'));

    // The "set up analytics" placeholder chart is SaaS-only; the appliance
    // branch only registers it when ! isAppliance().
    expect($provider)
        ->toContain('elseif (! isAppliance())')
        ->toContain('AnalyticsPlaceholderWidget::class');
});
