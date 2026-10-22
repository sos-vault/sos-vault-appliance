<?php

use App\Models\PlanFeature;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Wave\Plan;

/*
 * checkAccess('Alert Rules') gates the whole Alerts tool on SaaS. Plans and
 * PlanFeatures aren't present in a fresh test DB just from migrating — the
 * seed_alert_rules_plan_feature migration only backfills a Plan that already
 * exists — so these tests build the Plan/PlanFeature locally, mirroring
 * AssistantSettingsPageTest's settingsEnsureFeature() helper.
 */

function alertsGatingPlanWithFeature(string $planName, bool $enabled = true): void
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
        'enabled' => $enabled,
        'sort_order' => 99,
        'status' => 'ready',
    ]);
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::flush();
});

it('checkAccess("Alert Rules") is false for a Free-plan SaaS user with no feature grant', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Free']);

    expect(checkAccess($user, 'Alert Rules'))->toBeFalse();
});

it('checkAccess("Alert Rules") is true for a Team-plan SaaS user once the feature is granted', function () {
    alertsGatingPlanWithFeature('Team');

    $user = User::factory()->create();
    $user->syncRoles(['Team']);

    expect(checkAccess($user, 'Alert Rules'))->toBeTrue();
});

it('checkAccess("Alert Rules") is true for an Enterprise-plan SaaS user once the feature is granted', function () {
    alertsGatingPlanWithFeature('Enterprise');

    $user = User::factory()->create();
    $user->syncRoles(['Enterprise']);

    expect(checkAccess($user, 'Alert Rules'))->toBeTrue();
});

it('admins always pass checkAccess regardless of plan', function () {
    $user = User::factory()->create();
    $user->syncRoles(['admin']);

    expect(checkAccess($user, 'Alert Rules'))->toBeTrue();
});

it('the appliance branch is never gated by checkAccess — the tool bypasses it via isAppliance()', function () {
    Config::set('product.type', 'appliance');

    $user = User::factory()->create();
    // No SaaS-type Plan resolves for any appliance role, so checkAccess() alone
    // would always be false here — the tool's gate must check isAppliance() first.
    expect(checkAccess($user, 'Alert Rules'))->toBeFalse();
    expect(isAppliance() || checkAccess($user, 'Alert Rules'))->toBeTrue();
});
