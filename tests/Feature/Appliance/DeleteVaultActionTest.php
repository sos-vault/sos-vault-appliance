<?php

/**
 * Appliance group / vault sprint — the "Delete Vault" action on a Group
 * destroys the vault but leaves the Group row in place with vault_id=NULL.
 * Admin can re-provision a fresh vault for the same group later.
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

it('destroy-vault action removes the vault row but keeps the group', function () {
    $group = Group::create(['name' => 'Forensics', 'max_members' => 4]);
    $vault = VaultTools::createGroupVault($group, 512);
    $group->refresh();
    $vaultId = $vault->id;

    Livewire::test(ListGroups::class)
        ->callAction(TestAction::make('destroyVault')->table($group))
        ->assertHasNoErrors();

    expect(Vault::find($vaultId))->toBeNull();

    $group->refresh();
    expect($group)->not->toBeNull()
        ->and($group->vault_id)->toBeNull();

    $delVault = Sysevent::where('type', 'DEL_VAULT')
        ->where('status', 'SUCCESS')
        ->where('group', $group->id)
        ->first();
    expect($delVault)->not->toBeNull()
        ->and((int) $delVault->owner)->toBe($this->admin->id);
});
