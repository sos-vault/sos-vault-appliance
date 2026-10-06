<?php

/**
 * Appliance group / vault sprint — dissolving or deleting an appliance
 * group must destroy the group's vault (LUKS device + Vault row), detach
 * the member users from the group (or delete them, for the harsh
 * Delete-Group-and-Members action), and emit DEL_VAULT + DEL_GROUP
 * Sysevent rows attributed to the admin.
 */

use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

function makeApplianceGroupWithMembers(int $memberCount = 2): array
{
    $group = Group::create(['name' => 'Crew', 'max_members' => 5]);
    $vault = VaultTools::createGroupVault($group, 256);
    $members = collect();
    for ($i = 0; $i < $memberCount; $i++) {
        $u = User::factory()->create(['group_id' => $group->id]);
        $u->syncRoles(['Team Member']);
        $members->push($u->fresh());
    }

    return ['group' => $group->fresh(), 'vault' => $vault, 'members' => $members];
}

it('dissolve action destroys the vault and detaches members', function () {
    $ctx = makeApplianceGroupWithMembers(2);
    $group = $ctx['group'];
    $vaultId = $ctx['vault']->id;

    Livewire::test(ListGroups::class)
        ->callAction(TestAction::make('dissolve')->table($group))
        ->assertHasNoErrors();

    expect(Group::find($group->id))->toBeNull()
        ->and(Vault::find($vaultId))->toBeNull();

    // Member accounts remain but their group_id is cleared.
    foreach ($ctx['members'] as $m) {
        $fresh = User::find($m->id);
        expect($fresh)->not->toBeNull()
            ->and($fresh->group_id)->toBeNull();
    }

    $delVault = Sysevent::where('type', 'DEL_VAULT')->where('group', $group->id)->first();
    $delGroup = Sysevent::where('type', 'DEL_GROUP')->where('group', $group->id)->first();
    expect($delVault)->not->toBeNull()
        ->and($delGroup)->not->toBeNull();
});

it('delete-group-and-members removes member accounts on appliance', function () {
    $ctx = makeApplianceGroupWithMembers(2);
    $group = $ctx['group'];
    $vaultId = $ctx['vault']->id;
    $memberIds = $ctx['members']->pluck('id')->all();

    Livewire::test(ListGroups::class)
        ->callAction(TestAction::make('delete')->table($group))
        ->assertHasNoErrors();

    expect(Group::find($group->id))->toBeNull()
        ->and(Vault::find($vaultId))->toBeNull();

    foreach ($memberIds as $id) {
        expect(User::find($id))->toBeNull();
    }
});
