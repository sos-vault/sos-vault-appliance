<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * Regression: a tool page (Summary/Top/…) 500'd with "Attempt to read property
 * 'nodes' on null" when the cached .contents.json had gone stale — the on-disk
 * directory inode (which is the node id getDirById matches on) had diverged from
 * the cached tree, so getDirById($case->file_id) missed, DataTools left $tree
 * null, and getSummary() then dereferenced it. DataTools must self-heal by
 * regenerating the contents once and retrying before giving up.
 */

function staleCacheEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-stale-cache-'.bin2hex(random_bytes(4));
    mkdir($root, 0o755, true);
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
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-stale-cache-')) {
        exec('/bin/sudo /bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
        is_dir($root) && exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

it('self-heals a stale .contents.json so getDirById finds the real dir inode', function () {
    $env = staleCacheEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $mount = $env['mount'];

    // A real extracted sosreport directory on disk.
    $dirName = 'sosreport-host0-PROD-2026-01-01-stale';
    $src = "{$mount}/{$dirName}";
    mkdir($src, 0o755, true);
    file_put_contents("{$src}/uname", "Linux prod-host\n");

    $realInode = fileinode($src);
    expect($realInode)->toBeInt()->toBeGreaterThan(0);

    // Poison the root cache: the dir node carries a WRONG id (a stale inode),
    // exactly the divergence that produced "directory not found" in the field.
    $staleId = $realInode + 1_000_000;
    $staleCache = [
        'nodes' => [[
            'id' => 99999999,
            'name' => '',
            'path' => '',
            'type' => 'd',
            'nodes' => [[
                'id' => $staleId,
                'name' => $dirName,
                'path' => '',
                'type' => 'd',
            ]],
        ]],
    ];
    file_put_contents("{$mount}/.contents.json", json_encode($staleCache));

    // Sanity: with the poisoned cache the real inode is NOT resolvable.
    expect($vtools->getDirById($realInode))->toBeNull();

    // Constructing DataTools with the real inode must trigger the self-heal
    // (updateContents + retry), leaving $dir resolved rather than null.
    $dtools = new DataTools($vtools, $env['vault']->id, $realInode);

    $ref = new ReflectionProperty(DataTools::class, 'dir');
    $ref->setAccessible(true);
    $resolved = $ref->getValue($dtools);

    expect($resolved)->not->toBeNull();
    expect((int) $resolved->id)->toBe($realInode);

    // And the cache on disk was regenerated to the real inode.
    expect($vtools->getDirById($realInode))->not->toBeNull();
});
