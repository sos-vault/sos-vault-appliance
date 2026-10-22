<?php

/*
|--------------------------------------------------------------------------
| /alerts page + sidebar link
|--------------------------------------------------------------------------
|
| Alert rules are vault-wide, not tied to any one case, so — unlike the
| per-case /sosTool/{vid}/{did}/Alerts/{caseid} tool — /alerts resolves the
| user's vault itself (mirroring /vault and /fleet) and is reachable
| directly from the sidebar. See tests/Feature/Alerts/AlertGatingTest.php
| for the underlying checkAccess('Alert Rules') gating rules this reuses.
|
*/

use App\Models\PlanFeature;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Wave\Plan;

function alertsPageGrantFeature(string $planName): void
{
    $plan = Plan::where('type', 'service')->whereEnglishName($planName)->first();
    if (! $plan) {
        $role = Role::where('name', $planName)->first();
        $plan = Plan::create([
            'name' => $planName,
            'slug' => strtolower($planName),
            'status' => 'available',
            'type' => 'service',
            'features' => '{}',
            'role_id' => $role->id,
        ]);
    }

    PlanFeature::create([
        'plan_id' => $plan->id,
        'name' => 'Alert Rules',
        'type' => 'bool',
        'enabled' => true,
        'sort_order' => 99,
        'status' => 'ready',
    ]);
}

function alertsPageVault(User $user): Vault
{
    return Vault::create([
        'user_vault' => 'tv'.bin2hex(random_bytes(3)),
        'device' => 'unused',
        'header_file' => 'unused',
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $user->id,
        'group' => $user->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'current_size' => 1,
        'plan_size' => 1,
    ]);
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::flush();
});

it('redirects guests to login', function () {
    $this->get('/alerts')->assertRedirect('/login');
});

it('shows the Alerts sidebar link to a Team-plan user with access', function () {
    alertsPageGrantFeature('Team');
    $user = User::factory()->create();
    $user->syncRoles(['Team']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertStatus(200)
        ->assertSee(__('nav.nav_alerts'));
});

it('hides the Alerts sidebar link from a Free-plan SaaS user without access', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Free']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertStatus(200)
        ->assertDontSee(__('nav.nav_alerts'));
});

it('shows the Alerts sidebar link on the appliance regardless of plan', function () {
    Config::set('product.type', 'appliance');
    // An unlicensed appliance only allows the bootstrap admin to stay logged
    // in (BlockUnlicensedNonAdmin force-logs-out any non-admin) — an admin is
    // the only realistic appliance user for this scenario.
    $user = User::factory()->create();
    $user->syncRoles(['admin']);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertStatus(200)
        ->assertSee(__('nav.nav_alerts'));
});

it('renders the Alerts table for the current user\'s vault', function () {
    alertsPageGrantFeature('Team');
    $user = User::factory()->create();
    $user->syncRoles(['Team']);
    $vault = alertsPageVault($user);

    $this->actingAs($user)
        ->get('/alerts')
        ->assertStatus(200)
        ->assertSee(__('alerts.page_title'))
        ->assertSeeLivewire('alerts-table');
});

it('denies a Free-plan SaaS user direct access to /alerts', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Free']);
    alertsPageVault($user);

    $this->actingAs($user)->get('/alerts')->assertForbidden();
});

it('allows an unlicensed appliance user to reach /alerts', function () {
    Config::set('product.type', 'appliance');
    // See the sidebar test above: only the bootstrap admin can be logged in
    // on an unlicensed appliance.
    $user = User::factory()->create();
    $user->syncRoles(['admin']);
    $vault = alertsPageVault($user);

    $this->actingAs($user)
        ->get('/alerts')
        ->assertStatus(200)
        ->assertSeeLivewire('alerts-table');
});
