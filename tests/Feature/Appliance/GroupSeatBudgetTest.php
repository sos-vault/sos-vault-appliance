<?php

/**
 * Appliance group / vault sprint follow-up — the GroupResource Max Members
 * field must cap a new group's allocation at "license seats minus what
 * other groups have already reserved." Without this, the form's old behavior
 * (cap = full license seats) lets an admin over-commit at the group level
 * even though User::creating() still blocks the (seats+1)th account.
 *
 * Editing a group must exclude that group's own current max_members from
 * the sum — otherwise editing a group with the only allocation would
 * double-count and show 0 available.
 */

use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Groups\Pages\CreateGroup;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);

    LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 8,
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

it('seatsAvailableForGroup returns full seats when no groups exist', function () {
    expect(GroupResource::seatsAvailableForGroup(null))->toBe(8);
});

it('seatsAvailableForGroup subtracts other groups\' max_members on create', function () {
    Group::create(['name' => 'Group A', 'max_members' => 5]);

    expect(GroupResource::seatsAvailableForGroup(null))->toBe(3);
});

it('seatsAvailableForGroup excludes the edited group itself', function () {
    $groupA = Group::create(['name' => 'Group A', 'max_members' => 5]);
    Group::create(['name' => 'Group B', 'max_members' => 3]);

    // Editing Group A: only Group B (3) counts as "allocated elsewhere".
    expect(GroupResource::seatsAvailableForGroup($groupA))->toBe(5);
});

it('seatsAvailableForGroup floors at zero when groups are oversubscribed', function () {
    // Should not happen via the form, but if license shrinks after the
    // fact the helper must still return a non-negative number.
    Group::create(['name' => 'Group A', 'max_members' => 10]);

    expect(GroupResource::seatsAvailableForGroup(null))->toBe(0);
});

it('CreateGroup form rejects max_members larger than remaining seats', function () {
    Group::create(['name' => 'Group A', 'max_members' => 5]);
    // 3 seats remain.

    Livewire::test(CreateGroup::class)
        ->fillForm([
            'name' => 'Group B',
            'vault_size_mb' => 256,
            'max_members' => 6, // exceeds the 3 remaining
        ])
        ->call('create')
        ->assertHasFormErrors(['max_members']);

    expect(Group::where('name', 'Group B')->exists())->toBeFalse();
});

it('CreateGroup form accepts max_members equal to remaining seats', function () {
    Group::create(['name' => 'Group A', 'max_members' => 5]);
    // 3 seats remain — exactly 3 must be accepted.

    Livewire::test(CreateGroup::class)
        ->fillForm([
            'name' => 'Group B',
            'vault_size_mb' => 256,
            'max_members' => 3,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Group::where('name', 'Group B')->first()?->max_members)->toBe(3);
});

it('EditGroup form lets the edited group reuse its own current allocation', function () {
    $groupA = Group::create(['name' => 'Group A', 'max_members' => 5]);
    Group::create(['name' => 'Group B', 'max_members' => 3]);
    // Total 8 seats, fully allocated. Editing Group A back to 5 must work.

    Livewire::test(EditGroup::class, ['record' => $groupA->getRouteKey()])
        ->fillForm(['max_members' => 5])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('EditGroup form blocks bumping max_members past the per-group ceiling', function () {
    $groupA = Group::create(['name' => 'Group A', 'max_members' => 5]);
    Group::create(['name' => 'Group B', 'max_members' => 3]);
    // Group A could grow to at most 5 (8 license - 3 reserved by B).
    // Bumping to 6 must fail.

    Livewire::test(EditGroup::class, ['record' => $groupA->getRouteKey()])
        ->fillForm(['max_members' => 6])
        ->call('save')
        ->assertHasFormErrors(['max_members']);
});
