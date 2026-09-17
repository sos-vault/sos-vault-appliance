<?php

/**
 * Sprint 5 / PHASE 6 — appliance build guard.
 *
 * When config('product.type') === 'appliance' the SaaS-only surface must
 * disappear. The two acceptance probes from HANDOFF.md §4.4 Step A:
 *   - /admin/plans is gated by Resource::canAccess() → 403
 *   - /settings/subscription is a Folio page that aborts with 404
 *
 * The same routes must continue to render under the default 'saas' build.
 */

use App\Models\User;
use Database\Seeders\RolesTableSeeder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    $this->admin = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
    ]);
    $this->admin->syncRoles(['admin']);
});

it('returns 403 on /admin/plans when product.type is appliance', function () {
    config(['product.type' => 'appliance']);

    actingAs($this->admin);

    get('/admin/plans')->assertForbidden();
});

it('still serves /admin/plans under the saas build', function () {
    config(['product.type' => 'saas']);

    actingAs($this->admin);

    get('/admin/plans')->assertSuccessful();
});

it('returns 404 on /settings/subscription when product.type is appliance', function () {
    config(['product.type' => 'appliance']);

    actingAs($this->admin);

    get('/settings/subscription')->assertNotFound();
});
