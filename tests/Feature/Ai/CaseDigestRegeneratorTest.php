<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use App\Services\Ai\CaseDigestRegenerator;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(RolesTableSeeder::class);
    $this->dir = sys_get_temp_dir().'/cdr_'.uniqid();
    mkdir($this->dir);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    array_map('unlink', glob($this->dir.'/.*[!.]*') ?: []);
    @rmdir($this->dir);
});

it('does not regenerate when a digest already exists', function () {
    file_put_contents($this->dir.'/.aiDigest.json', '{}');

    $user = User::factory()->create();
    Vault::factory()->create(['owner' => $user->id]);
    $vtools = new VaultTools($user);

    // Digest present + not forced → returns false without touching DataTools.
    expect((new CaseDigestRegenerator)->ensure($vtools, 'vid', 1, $this->dir))->toBeFalse();
});

it('reports failure gracefully when the case cannot be read (no throw)', function () {
    // No digest, and the path/vault has no extracted report → generation fails
    // but is swallowed and reported as false rather than throwing.
    $user = User::factory()->create();
    Vault::factory()->create(['owner' => $user->id]);
    $vtools = new VaultTools($user);

    expect((new CaseDigestRegenerator)->ensure($vtools, 'vid', 99999999, $this->dir))->toBeFalse()
        ->and(is_file($this->dir.'/.aiDigest.json'))->toBeFalse();
});
