<?php

/*
|--------------------------------------------------------------------------
| Fleet identity backfill (service + fleet:backfill-identity command)
|--------------------------------------------------------------------------
|
| Pre-fleet cases carry no machine_id/hostname; the real identity lives in the
| per-report .hostData.json inside the vault. The backfiller reads that cache
| (or regenerates it) and persists the identity on the case. Closed vaults
| cannot be read and are skipped.
|
*/

use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use App\Services\Fleet\FleetIdentityBackfiller;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(RolesTableSeeder::class);
    $this->dir = sys_get_temp_dir().'/fib_'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    array_map('unlink', glob($this->dir.'/.*[!.]*') ?: []);
    @rmdir($this->dir);
});

function fibVtools(): VaultTools
{
    $user = User::factory()->create();
    Vault::factory()->create(['owner' => $user->id]);

    return new VaultTools($user);
}

it('populates machine_id and hostname from a cached .hostData.json', function () {
    file_put_contents($this->dir.'/.hostData.json', json_encode([
        'hostname' => 'web01.example.com',
        'machineid' => 'ec2f3a9b8c7d6e5f4a3b2c1d0e9f8a7b',
    ]));

    $case = SupportCase::factory()->create(['machine_id' => null, 'hostname' => null]);

    $updated = (new FleetIdentityBackfiller)->ensure(fibVtools(), 'vid', 1, $this->dir, $case);

    expect($updated)->toBeTrue()
        ->and($case->fresh())
        ->machine_id->toBe('ec2f3a9b8c7d6e5f4a3b2c1d0e9f8a7b')
        ->hostname->toBe('web01.example.com');
});

it('is a no-op when the identity is already populated', function () {
    file_put_contents($this->dir.'/.hostData.json', json_encode([
        'hostname' => 'other', 'machineid' => 'ffffffffffffffffffffffffffffffff',
    ]));

    $case = SupportCase::factory()->create(['machine_id' => 'aaaa', 'hostname' => 'web01']);

    expect((new FleetIdentityBackfiller)->ensure(fibVtools(), 'vid', 1, $this->dir, $case))->toBeFalse()
        ->and($case->fresh()->machine_id)->toBe('aaaa');
});

it('does not persist an empty machineid (stays NULL for host fallback)', function () {
    file_put_contents($this->dir.'/.hostData.json', json_encode([
        'hostname' => 'clean0', 'machineid' => '',
    ]));

    $case = SupportCase::factory()->create(['machine_id' => null, 'hostname' => null]);

    (new FleetIdentityBackfiller)->ensure(fibVtools(), 'vid', 1, $this->dir, $case);

    expect($case->fresh())
        ->machine_id->toBeNull()
        ->hostname->toBe('clean0');
});

it('fails gracefully when the identity cannot be read (no throw)', function () {
    // No .hostData.json and no extracted report behind the did → regeneration
    // fails, is swallowed, and the case is left untouched.
    $case = SupportCase::factory()->create(['machine_id' => null, 'hostname' => null]);

    expect((new FleetIdentityBackfiller)->ensure(fibVtools(), 'vid', 99999999, $this->dir, $case))->toBeFalse()
        ->and($case->fresh()->machine_id)->toBeNull();
});

it('command reports closed vaults as skipped', function () {
    // Force the real /dev/mapper open-check so factory vaults read as "closed"
    // (in-memory tests have no mounted LUKS device).
    config(['app.vaultsDisabled' => 'FALSE']);

    $user = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $user->id]);
    SupportCase::factory()->create([
        'vault_id' => $vault->id, 'file_id' => 42, 'owner' => $user->id,
        'machine_id' => null, 'hostname' => null,
    ]);

    $this->artisan('fleet:backfill-identity')
        ->expectsOutputToContain('skipped (vault closed): 1')
        ->assertSuccessful();
});

it('command counts already-populated cases without touching the vault', function () {
    config(['app.vaultsDisabled' => 'FALSE']);

    $user = User::factory()->create();
    $vault = Vault::factory()->create(['owner' => $user->id]);
    SupportCase::factory()->create([
        'vault_id' => $vault->id, 'file_id' => 42, 'owner' => $user->id,
        'machine_id' => 'aaaa', 'hostname' => 'web01',
    ]);

    $this->artisan('fleet:backfill-identity')
        ->expectsOutputToContain('already present: 1')
        ->assertSuccessful();
});

it('command reports unresolved when the case vault/owner cannot be found', function () {
    SupportCase::factory()->create(['vault_id' => 999999, 'file_id' => 5, 'machine_id' => null]);

    $this->artisan('fleet:backfill-identity')
        ->expectsOutputToContain('unresolved: 1')
        ->assertSuccessful();
});
