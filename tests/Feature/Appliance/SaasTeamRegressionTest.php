<?php

/**
 * Appliance sprint regression guard — the SaaS-side VaultTools constructor
 * and the GroupResource form must keep working when product.type=saas.
 * The refactored constructor (which can accept a Group context) and the
 * isAppliance() gates added throughout must be invisible to SaaS.
 */

use App\Models\Group;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;

beforeEach(function () {
    config(['product.type' => 'saas']);
    $this->seed(RolesTableSeeder::class);
});

it('VaultTools still resolves a SaaS user personal vault', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Free']);
    $vault = Vault::factory()->create(['owner' => $user->id]);

    $vtools = new VaultTools($user->fresh());

    expect((int) $vtools->getVaultId())->toBe($vault->id);
});

it('VaultTools still resolves a SaaS Team member through the group vault', function () {
    $manager = User::factory()->create();
    $manager->syncRoles(['Team']);
    $vault = Vault::factory()->create(['owner' => $manager->id]);
    $group = Group::create([
        'name' => 'Manager Group',
        'owner_id' => $manager->id,
        'vault_id' => $vault->id,
        'max_members' => 8,
    ]);
    $member = User::factory()->create(['group_id' => $group->id]);
    $member->syncRoles(['Team']);

    $vtools = new VaultTools($member->fresh());

    expect((int) $vtools->getVaultId())->toBe($vault->id);
});
