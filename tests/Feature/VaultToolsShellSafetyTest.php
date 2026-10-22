<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/**
 * VaultTools interpolates vname/device/mountp into privileged root shell
 * commands (cryptsetup, mount, mkfs, dd, rm -rf). The constructor enforces that
 * those values are free of shell metacharacters so a tampered DB row can never
 * reach a root exec. These tests lock that invariant in.
 */
beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    // Keep the constructor away from real LUKS/keyring work; the safety guard
    // runs before any of that regardless.
    Config::set('app.vaultsDisabled', 'TRUE');
});

it('constructs normally for a system-generated (hex) vault name', function () {
    $user = User::factory()->create();
    $vault = Vault::factory()->create([
        'owner' => $user->id,
        'user_vault' => md5('someone'),
        'device' => '/vault/.'.md5('someone').'.img',
    ]);

    $this->actingAs($user);

    expect(new VaultTools($user, $vault->id))->toBeInstanceOf(VaultTools::class);
});

it('refuses a vault whose name carries shell metacharacters', function () {
    $user = User::factory()->create();
    $vault = Vault::factory()->create([
        'owner' => $user->id,
        'user_vault' => 'abc; rm -rf /',
    ]);

    $this->actingAs($user);

    expect(fn () => new VaultTools($user, $vault->id))->toThrow(RuntimeException::class);
});

it('refuses a backing-device path with a command substitution', function () {
    $user = User::factory()->create();
    $vault = Vault::factory()->create([
        'owner' => $user->id,
        'user_vault' => md5('safe'),
        'device' => '/vault/$(reboot).img',
    ]);

    $this->actingAs($user);

    expect(fn () => new VaultTools($user, $vault->id))->toThrow(RuntimeException::class);
});
