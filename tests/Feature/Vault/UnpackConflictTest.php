<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * Pre-flight conflict check for VaultTools::xtract() — if a directory with the
 * expected unpacked name already exists in the vault, the extraction must
 * abort rather than silently merge into the existing tree. Covers the path
 * "user repacked without deleting → tries to unpack the new archive".
 */

function conflictTempRoot(): string
{
    $root = sys_get_temp_dir().'/sos-vault-conflict-test-'.bin2hex(random_bytes(4));
    mkdir($root, 0o755, true);

    return $root;
}

function buildConflictEnv(): array
{
    $root = conflictTempRoot();
    Config::set('filesystems.disks.vault.root', $root);
    Config::set('app.vaultsDisabled', 'TRUE');

    $user = User::factory()->create();
    $user->syncRoles(['admin']);

    $vname = 'tv'.bin2hex(random_bytes(3));
    $mountp = "{$root}/{$vname}";
    mkdir($mountp, 0o755, true);

    $vault = Vault::create([
        'user_vault' => $vname,
        'device' => "{$root}/.{$vname}.img",
        'header_file' => "{$root}/.headers/.{$vname}.data",
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $user->id,
        'group' => $user->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'description' => 'test vault',
        'current_size' => 100 * 1024 * 1024,
        'plan_size' => 100 * 1024 * 1024,
    ]);

    return ['user' => $user, 'vault' => $vault, 'mount' => $mountp, 'root' => $root];
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-conflict-test-')) {
        exec('/bin/sudo /bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

it('aborts unpack when the expected extracted directory already exists', function () {
    $env = buildConflictEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $mount = $env['mount'];

    // Stage 1: build a real tar.xz archive of a sosreport directory.
    $dirName = 'sosreport-host-rhel-2026-01-01-conflict';
    $src = "{$mount}/{$dirName}";
    mkdir($src, 0o755, true);
    file_put_contents("{$src}/uname", "Linux conflict-host\n");

    $archive = "{$mount}/{$dirName}.tar.xz";
    exec(sprintf(
        '/bin/tar -C %s -Jcf %s %s 2>&1',
        escapeshellarg($mount),
        escapeshellarg($archive),
        escapeshellarg($dirName),
    ), $out, $ret);
    expect($ret)->toBe(0, 'fixture archive build failed: '.implode("\n", $out));

    // Keep the directory in place — that's the conflict the test is exercising.
    expect(is_dir($src))->toBeTrue();

    // Stage 2: invoke xtract() via reflection; it must refuse to extract
    // because $expectednewdir already exists on disk.
    $ref = new ReflectionMethod(VaultTools::class, 'xtract');
    $ref->setAccessible(true);

    $attrs = (object) ['is_xz' => true, 'is_gzip' => false, 'is_bzip' => false, 'is_zip' => false];
    $ok = $ref->invoke($vtools, $archive, "{$mount}/", $src, null, $attrs);

    expect($ok)->toBeFalse();
    expect($vtools->emessage)->toBe(__('vault.unpack_conflict'));
    expect($vtools->ePhase)->toBe('extract');
});
