<?php

/**
 * Regression guard for the SaaS bulk-delete behavior on the Groups
 * admin page. The 2026-05-22 appliance group / vault refactor briefly
 * collapsed bulk-delete into "just detach members" on both branches;
 * SaaS still needs the legacy "delete members except the owner, then
 * detach the owner" semantic. Appliance bulk-delete destroys the
 * group vault and deletes member accounts (consistent with the
 * single-record Delete action on the appliance branch).
 */

use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

it('SaaS bulk-delete deletes member accounts except the owner', function () {
    config(['product.type' => 'saas']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $manager = User::factory()->create();
    $manager->syncRoles(['Team']);
    $group = Group::create([
        'name' => 'Team A',
        'owner_id' => $manager->id,
        'max_members' => 5,
    ]);
    $manager->update(['group_id' => $group->id]);
    $member = User::factory()->create(['group_id' => $group->id]);

    Livewire::test(ListGroups::class)
        ->callTableBulkAction('delete', [$group->id])
        ->assertHasNoErrors();

    expect(Group::find($group->id))->toBeNull()
        // Member account was deleted (legacy SaaS cascade).
        ->and(User::find($member->id))->toBeNull()
        // Owner survives, with group_id cleared.
        ->and(User::find($manager->id))->not->toBeNull()
        ->and(User::find($manager->id)->group_id)->toBeNull();
});

it('appliance bulk-delete destroys the vault and deletes members', function () {
    config(['product.type' => 'appliance']);
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
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $group = Group::create(['name' => 'Crew', 'max_members' => 5]);
    $vault = VaultTools::createGroupVault($group, 256);
    $member = User::factory()->create(['group_id' => $group->id]);
    $member->syncRoles(['Team Member']);

    Livewire::test(ListGroups::class)
        ->callTableBulkAction('delete', [$group->id])
        ->assertHasNoErrors();

    expect(Group::find($group->id))->toBeNull()
        ->and(Vault::find($vault->id))->toBeNull()
        ->and(User::find($member->id))->toBeNull();
});
