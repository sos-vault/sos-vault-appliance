<?php

use App\Console\Commands\CloseUnattendedVaults;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('selects users whose OPEN non-always_open vaults have stale last_activity', function () {
    $this->seed(RolesTableSeeder::class);
    $cutoff = 1_000_000;

    $dropped = User::factory()->create(['last_activity' => $cutoff - 1]);
    $active = User::factory()->create(['last_activity' => $cutoff + 1]);
    $alwaysOpen = User::factory()->create(['last_activity' => $cutoff - 1]);
    $closedVault = User::factory()->create(['last_activity' => $cutoff - 1]);

    Vault::factory()->open()->forUser($dropped->id)->create();
    Vault::factory()->open()->forUser($active->id)->create();
    Vault::factory()->open()->forUser($alwaysOpen->id)->create(['always_open' => true]);
    Vault::factory()->forUser($closedVault->id)->create();

    $selected = CloseUnattendedVaults::usersWithUnattendedVaults($cutoff)->pluck('id')->all();

    expect($selected)->toBe([$dropped->id]);
});
