<?php

/**
 * Appliance group / vault sprint — when a Team Member with group_id logs
 * in, VaultTools must resolve their shared group vault (not create a
 * personal vault). The initializeVault listener short-circuits the SaaS
 * vault-creation path on appliance.
 */

use App\Listeners\initializeVault;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);

    LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 10,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => 'stub',
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);
});

it('VaultTools resolves the shared group vault for a member', function () {
    $group = Group::create(['name' => 'Crew', 'max_members' => 4]);
    $vault = VaultTools::createGroupVault($group, 256);

    $member = User::factory()->create(['group_id' => $group->id]);
    $member->syncRoles(['Team Member']);
    $member = User::find($member->id); // refresh relations

    $vtools = new VaultTools($member);

    expect((int) $vtools->getVaultId())->toBe($vault->id);

    // The member must NOT have a personal vault row.
    expect(Vault::where('owner', $member->id)->exists())->toBeFalse();
});

it('initializeVault skips members without a group on appliance', function () {
    $member = User::factory()->create(['group_id' => null, 'email_verified_at' => now()]);
    $member->syncRoles(['Team Member']);

    $listener = new initializeVault;
    $listener->handle(new Login('web', $member, false));

    expect(Vault::count())->toBe(0);
});

it('initializeVault registers active user on the group vault for a member', function () {
    $group = Group::create(['name' => 'Crew', 'max_members' => 4]);
    $vault = VaultTools::createGroupVault($group, 256);

    $member = User::factory()->create([
        'group_id' => $group->id,
        'email_verified_at' => now(),
    ]);
    $member->syncRoles(['Team Member']);
    $member = User::find($member->id);

    // vaultsDisabled=TRUE — we just verify the listener doesn't throw and
    // doesn't create a personal vault for the member.
    $listener = new initializeVault;
    $listener->handle(new Login('web', $member, false));

    expect(Vault::where('owner', $member->id)->exists())->toBeFalse()
        ->and(Vault::count())->toBe(1)
        ->and(Vault::first()->id)->toBe($vault->id);
});
