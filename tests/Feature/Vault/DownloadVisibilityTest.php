<?php

use App\Models\Group;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * canDownloadVaultFile() permission matrix — gate for the Download action on
 * the packed-files table in resources/themes/anchor/pages/vault/index.blade.php.
 *
 *   SaaS:
 *     admin                         → yes (also when impersonating another user)
 *     Free / Minimal / Basic        → yes
 *     Team / Enterprise             → only if isTeamManager()
 *   Standalone (appliance):
 *     admin                         → yes (also when impersonating)
 *     anyone else                   → no
 */

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

it('returns false when no user is authenticated', function () {
    Config::set('product.type', 'saas');
    expect(canDownloadVaultFile(null))->toBeFalse();
});

it('allows admin on SaaS', function () {
    Config::set('product.type', 'saas');
    $u = User::factory()->create();
    $u->syncRoles(['admin']);
    expect(canDownloadVaultFile($u))->toBeTrue();
});

it('allows admin on standalone', function () {
    Config::set('product.type', 'appliance');
    $u = User::factory()->create();
    $u->syncRoles(['admin']);
    expect(canDownloadVaultFile($u))->toBeTrue();
});

it('allows Free / Minimal / Basic on SaaS', function (string $role) {
    Config::set('product.type', 'saas');
    $u = User::factory()->create();
    $u->syncRoles([$role]);
    expect(canDownloadVaultFile($u))->toBeTrue();
})->with(['Free', 'Minimal', 'Basic']);

it('denies Team plan non-managers on SaaS', function () {
    Config::set('product.type', 'saas');
    $u = User::factory()->create();
    $u->syncRoles(['Team']);
    expect(canDownloadVaultFile($u))->toBeFalse();
});

it('allows Team plan managers on SaaS', function () {
    Config::set('product.type', 'saas');
    $manager = User::factory()->create();
    $manager->syncRoles(['Team']);
    Group::create(['name' => 'mgr group', 'owner_id' => $manager->id]);
    expect(canDownloadVaultFile($manager))->toBeTrue();
});

it('denies Enterprise plan non-managers on SaaS', function () {
    Config::set('product.type', 'saas');
    $u = User::factory()->create();
    $u->syncRoles(['Enterprise']);
    expect(canDownloadVaultFile($u))->toBeFalse();
});

it('allows Enterprise plan managers on SaaS', function () {
    Config::set('product.type', 'saas');
    $manager = User::factory()->create();
    $manager->syncRoles(['Enterprise']);
    Group::create(['name' => 'ent group', 'owner_id' => $manager->id]);
    expect(canDownloadVaultFile($manager))->toBeTrue();
});

it('denies non-admin roles on standalone', function (string $role) {
    Config::set('product.type', 'appliance');
    $u = User::factory()->create();
    $u->syncRoles([$role]);
    expect(canDownloadVaultFile($u))->toBeFalse();
})->with(['Free', 'Minimal', 'Basic', 'Team', 'Enterprise', 'Self-hosted']);

it('allows admin when impersonating another user (SaaS)', function () {
    Config::set('product.type', 'saas');
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $target = User::factory()->create();
    $target->syncRoles(['Team']); // would normally be denied (non-manager)

    // Drive the impersonation session keys directly. Going through
    // $admin->impersonate($target) would also fire TakeImpersonation, which
    // (via EventServiceProvider) calls registerActiveUser with the target's
    // empty vault id and trips on the int type-hint. The helper only reads
    // isImpersonating() + getImpersonatorId(), so populating the session keys
    // is enough to exercise the rule.
    session()->put(app('impersonate')->getSessionKey(), $admin->id);
    session()->put(app('impersonate')->getSessionGuard(), 'web');

    $this->actingAs($target);

    expect(canDownloadVaultFile())->toBeTrue();

    app('impersonate')->clear();
});

it('allows admin when impersonating another user (standalone)', function () {
    // Create users while in SaaS mode — the appliance branch enforces a
    // single-admin guard that would block factory user creation. Flip to
    // appliance only once the users exist so the permission rule sees the
    // standalone product type.
    Config::set('product.type', 'saas');
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    $target = User::factory()->create();
    $target->syncRoles(['Self-hosted']);

    Config::set('product.type', 'appliance');

    session()->put(app('impersonate')->getSessionKey(), $admin->id);
    session()->put(app('impersonate')->getSessionGuard(), 'web');

    $this->actingAs($target);

    expect(canDownloadVaultFile())->toBeTrue();

    app('impersonate')->clear();
});
