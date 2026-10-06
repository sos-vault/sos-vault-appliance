<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

/** Create a user with an associated vault row (always_open defaults to false). */
function userWithVault(bool $alwaysOpen = false): User
{
    $user = User::factory()->create();
    Vault::factory()->create(['owner' => $user->id, 'always_open' => $alwaysOpen]);

    return $user;
}

// ---------------------------------------------------------------------------
// Pin Vault Open
// ---------------------------------------------------------------------------

it('pin vault action sets always_open to true', function () {
    $target = userWithVault(alwaysOpen: false);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('pinVault')->table($target))
        ->assertHasNoErrors();

    expect((bool) Vault::where('owner', $target->id)->value('always_open'))->toBeTrue();
});

it('pin vault action sends a success notification', function () {
    $target = userWithVault(alwaysOpen: false);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('pinVault')->table($target));

    Notification::assertNotified('Vault pinned open');
});

it('pin vault action is hidden when vault is already pinned', function () {
    $target = userWithVault(alwaysOpen: true);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('pinVault', $target);
});

it('pin vault action is hidden when user has no vault', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('pinVault', $target);
});

// ---------------------------------------------------------------------------
// Unpin Vault
// ---------------------------------------------------------------------------

it('unpin vault action sets always_open to false', function () {
    $target = userWithVault(alwaysOpen: true);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('unpinVault')->table($target))
        ->assertHasNoErrors();

    expect((bool) Vault::where('owner', $target->id)->value('always_open'))->toBeFalse();
});

it('unpin vault action sends a success notification', function () {
    $target = userWithVault(alwaysOpen: true);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('unpinVault')->table($target));

    Notification::assertNotified('Vault unpinned');
});

it('unpin vault action is hidden when vault is not pinned', function () {
    $target = userWithVault(alwaysOpen: false);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('unpinVault', $target);
});

it('unpin vault action is hidden when user has no vault', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('unpinVault', $target);
});

// ---------------------------------------------------------------------------
// Mutual exclusivity
// ---------------------------------------------------------------------------

it('only pin action is visible when vault is not pinned', function () {
    $target = userWithVault(alwaysOpen: false);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('pinVault', $target)
        ->assertTableActionHidden('unpinVault', $target);
});

it('only unpin action is visible when vault is pinned', function () {
    $target = userWithVault(alwaysOpen: true);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible('unpinVault', $target)
        ->assertTableActionHidden('pinVault', $target);
});

// ---------------------------------------------------------------------------
// Close Vault — admin must be able to force-close a pinned (always_open) vault
// ---------------------------------------------------------------------------

it('admin close vault action force-closes a pinned vault', function () {
    // Disable the vaultsDisabled short-circuit at the top of closeVault so
    // the always_open guard is what determines whether the close proceeds.
    \Illuminate\Support\Facades\Config::set('app.vaultsDisabled', 'FALSE');

    $target = User::factory()->create();
    Vault::factory()->create([
        'owner' => $target->id,
        'always_open' => true,
        'status' => 'OPEN',
    ]);

    $this->actingAs($this->admin);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('closeVault')->table($target))
        ->assertHasNoErrors();

    // With force=true the always_open guard is bypassed and the no-real-device
    // path updates status to CLOSED. always_open stays true so RemountPublicVaults
    // will remount on next reboot — this is a *temporary* admin close.
    $vault = Vault::where('owner', $target->id)->first();
    expect($vault->status)->toBe('CLOSED');
    expect((bool) $vault->always_open)->toBeTrue();

    Notification::assertNotified('Vault closed');
});
