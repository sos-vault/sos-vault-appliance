<?php

use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\Group;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Services\VaultAccess;
use Database\Seeders\RolesTableSeeder;

/**
 * Authorization matrix for VaultAccess::allows() — the gate that closed the
 * cross-tenant vault-read IDOR (resolveVaultUser used to elevate to the vault
 * owner for ANY caller). Access must be granted only to the owner, group
 * members, admins, and callers viewing a public / same-group case in that vault.
 */
beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

it('allows the vault owner', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    expect(VaultAccess::allows($owner, $vault->id))->toBeTrue();
});

it('denies an unrelated authenticated user (the IDOR case)', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    expect(VaultAccess::allows($attacker, $vault->id))->toBeFalse();
});

it('denies when there is no authenticated user', function () {
    $vault = Vault::factory()->create();

    expect(VaultAccess::allows(null, $vault->id))->toBeFalse();
});

it('denies an invalid vault id', function () {
    $user = User::factory()->create();

    expect(VaultAccess::allows($user, 0))->toBeFalse();
});

it('allows an admin to read any vault', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    expect(VaultAccess::allows($admin, $vault->id))->toBeTrue();
});

it('allows a member of the group that owns the vault', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $group = Group::factory()->create(['vault_id' => $vault->id]);

    $member = User::factory()->create(['group_id' => $group->id]);

    expect(VaultAccess::allows($member, $vault->id))->toBeTrue();
});

it('allows any user to read a public case in that vault', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $case = SupportCase::factory()->create([
        'owner' => $owner->id,
        'vault_id' => $vault->id,
        'is_public' => true,
    ]);

    expect(VaultAccess::allows($viewer, $vault->id, $case->id))->toBeTrue();
});

it('does not let a public case unlock a different vault', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $viewer = User::factory()->create();
    $publicVault = Vault::factory()->create(['owner' => $owner->id]);
    $otherVault = Vault::factory()->create(['owner' => $otherOwner->id]);
    $case = SupportCase::factory()->create([
        'owner' => $owner->id,
        'vault_id' => $publicVault->id,
        'is_public' => true,
    ]);

    // The case is public but lives in $publicVault; it must not authorize
    // reading a different vault the viewer has no rights to.
    expect(VaultAccess::allows($viewer, $otherVault->id, $case->id))->toBeFalse();
});

it('allows a same-group case even when not public', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $group = Group::factory()->create(['vault_id' => null]);
    $member = User::factory()->create(['group_id' => $group->id]);

    $case = SupportCase::factory()->create([
        'owner' => $owner->id,
        'vault_id' => $vault->id,
        'group' => $group->id,
        'is_public' => false,
    ]);

    expect(VaultAccess::allows($member, $vault->id, $case->id))->toBeTrue();
});

it('allows any user to read a file explicitly shared with them', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    ContentsRequest::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961,
        'owner' => $owner->id, 'status' => 'SHARED',
    ]);

    expect(VaultAccess::allows($viewer, $vault->id, null, 16, 4961))->toBeTrue();
});

it('scopes a file share to that file only (no sibling access)', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    ContentsRequest::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961,
        'owner' => $owner->id, 'status' => 'SHARED',
    ]);

    // A different, unshared file in the same directory must stay private.
    expect(VaultAccess::allows($viewer, $vault->id, null, 16, 9999))->toBeFalse();
});

it('denies a file whose share is not active (expired / not shared)', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    ContentsRequest::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961,
        'owner' => $owner->id, 'status' => 'EXPIRED',
    ]);

    expect(VaultAccess::allows($viewer, $vault->id, null, 16, 4961))->toBeFalse();
});

it('allows files under a shared directory', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    ContentsRequest::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 0,
        'owner' => $owner->id, 'status' => 'SHARED',
    ]);

    // Any file in dir 16 is reachable via the directory share.
    expect(VaultAccess::allows($viewer, $vault->id, null, 16, 4961))->toBeTrue();
});

// ---------------------------------------------------------------------------
// canManage() — write capability (share / annotate / flip public)
// ---------------------------------------------------------------------------

it('lets the owner, group member and admin manage the vault', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $group = Group::factory()->create(['vault_id' => $vault->id]);
    $member = User::factory()->create(['group_id' => $group->id]);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    expect(VaultAccess::canManage($owner, $vault->id))->toBeTrue()
        ->and(VaultAccess::canManage($member, $vault->id))->toBeTrue()
        ->and(VaultAccess::canManage($admin, $vault->id))->toBeTrue();
});

it('forbids an unrelated user from managing the vault', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $stranger = User::factory()->create();

    expect(VaultAccess::canManage($stranger, $vault->id))->toBeFalse();
});

it('forbids a share recipient from managing (re-sharing) the vault', function () {
    // A user who can READ a shared file must not be able to WRITE / re-share it.
    $owner = User::factory()->create();
    $recipient = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    ContentsRequest::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961,
        'owner' => $owner->id, 'status' => 'SHARED',
    ]);

    expect(VaultAccess::allows($recipient, $vault->id, null, 16, 4961))->toBeTrue()
        ->and(VaultAccess::canManage($recipient, $vault->id))->toBeFalse();
});

it('keeps a shared file readable after a note-save clobbers the request status', function () {
    // saveAnnotations() overwrites ContentsRequest.status with the lock flag
    // (historically 'true'/'false'). The share must survive that — the "is
    // shared" signal is the Annotation, not the ContentsRequest status.
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    ContentsRequest::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961,
        'owner' => $owner->id, 'status' => 'false', // clobbered by a note-save
    ]);
    Annotation::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961,
        'owner' => $owner->id, 'status' => 'SHARED',
    ]);

    expect(VaultAccess::allows($viewer, $vault->id, null, 16, 4961))->toBeTrue();
});

it('denies a file that was unshared (annotation PRIVATE)', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);

    ContentsRequest::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961,
        'owner' => $owner->id, 'status' => 'SHARED',
    ]);
    Annotation::factory()->create([
        'vault_id' => $vault->id, 'dir_id' => 16, 'file_id' => 4961,
        'owner' => $owner->id, 'status' => 'PRIVATE', // unshared
    ]);

    expect(VaultAccess::allows($viewer, $vault->id, null, 16, 4961))->toBeFalse();
});

it('denies a private case belonging to another group', function () {
    $owner = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $owner->id]);
    $attacker = User::factory()->create(['group_id' => null]);

    $case = SupportCase::factory()->create([
        'owner' => $owner->id,
        'vault_id' => $vault->id,
        'group' => 999,
        'is_public' => false,
    ]);

    expect(VaultAccess::allows($attacker, $vault->id, $case->id))->toBeFalse();
});
