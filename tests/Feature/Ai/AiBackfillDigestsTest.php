<?php

use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(RolesTableSeeder::class);
    // Force the real /dev/mapper open-check so factory vaults read as "closed"
    // (in-memory tests have no mounted LUKS device).
    config(['app.vaultsDisabled' => 'FALSE']);
});

it('skips cases whose vault is not currently open', function () {
    $user = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $user->id]);
    SupportCase::factory()->create(['vault_id' => $vault->id, 'file_id' => 123, 'owner' => $user->id]);

    artisan('ai:backfill-digests')
        ->expectsOutputToContain('skipped (vault closed): 1')
        ->assertExitCode(0);
});

it('reports unresolved when the case vault/owner cannot be found', function () {
    SupportCase::factory()->create(['vault_id' => 999999, 'file_id' => 5]);

    artisan('ai:backfill-digests')
        ->expectsOutputToContain('unresolved: 1')
        ->assertExitCode(0);
});

it('honours the --case filter', function () {
    $user = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $user->id]);
    $target = SupportCase::factory()->create(['vault_id' => $vault->id, 'file_id' => 1, 'owner' => $user->id]);
    SupportCase::factory()->create(['vault_id' => $vault->id, 'file_id' => 2, 'owner' => $user->id]);

    // Only the targeted case is considered → exactly one closed-vault skip.
    artisan('ai:backfill-digests', ['--case' => $target->id])
        ->expectsOutputToContain('skipped (vault closed): 1')
        ->assertExitCode(0);
});
