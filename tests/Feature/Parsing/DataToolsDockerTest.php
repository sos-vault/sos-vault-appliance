<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * DataTools::getDockerData() — cached-dotfile parsing of the sosreport docker
 * plugin capture. Uses the lightweight vaultsDisabled=TRUE harness (real files
 * on a plain temp directory, no LUKS mount) — see DataToolsStaleCacheTest for
 * the same convention.
 */

function dockerTestEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-docker-'.bin2hex(random_bytes(4));
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

function dockerInfoFixture(): string
{
    return <<<'TXT'
Client:
 Context:    default
 Debug Mode: false

Server:
 Containers: 3
  Running: 1
  Paused: 0
  Stopped: 2
 Images: 5
 Server Version: 24.0.7
 Storage Driver: overlay2
  Backing Filesystem: extfs
 Logging Driver: json-file
 Cgroup Driver: systemd
 Security Options:
  apparmor
  seccomp
   Profile: builtin
  cgroupns
 Docker Root Dir: /var/lib/docker
 Live Restore Enabled: false
TXT;
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-docker-')) {
        exec('/bin/sudo /bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
        is_dir($root) && exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
    Config::set('app.vaultsDisabled', 'TRUE');
});

it('parses docker_info and daemon.json into the expected fields', function () {
    $env = dockerTestEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $mount = $vtools->getMountPoint();

    $dirName = 'sosreport-host0-CASE-2026-01-01-docker';
    $src = "{$mount}/{$dirName}";
    mkdir("{$src}/sos_commands/docker", 0o755, true);
    mkdir("{$src}/etc/docker", 0o755, true);
    file_put_contents("{$src}/sos_commands/docker/docker_info", dockerInfoFixture());
    file_put_contents(
        "{$src}/etc/docker/daemon.json",
        json_encode(['log-driver' => 'json-file', 'storage-driver' => 'overlay2'], JSON_PRETTY_PRINT)
    );

    $dirId = fileinode($src);
    expect($dirId)->toBeInt()->toBeGreaterThan(0);

    $dtools = new DataTools($vtools, $env['vault']->id, $dirId);

    $docker = $dtools->getDockerData();

    expect($docker)->not->toBeNull()
        ->and($docker['docker_root_dir'])->toBe('/var/lib/docker')
        ->and($docker['storage_driver'])->toBe('overlay2')
        ->and($docker['logging_driver'])->toBe('json-file')
        ->and($docker['server_version'])->toBe('24.0.7')
        ->and($docker['security_options'])->toBe(['apparmor', 'seccomp', 'cgroupns'])
        ->and($docker['daemon_config'])->toBe(['log-driver' => 'json-file', 'storage-driver' => 'overlay2']);

    expect(is_file("{$src}/.dockerData.json"))->toBeTrue();
});

it('re-reads from cache on a second call without re-parsing the source files', function () {
    $env = dockerTestEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $mount = $vtools->getMountPoint();

    $dirName = 'sosreport-host0-CASE-2026-01-01-cache';
    $src = "{$mount}/{$dirName}";
    mkdir("{$src}/sos_commands/docker", 0o755, true);
    file_put_contents("{$src}/sos_commands/docker/docker_info", dockerInfoFixture());

    $dirId = fileinode($src);
    $dtools = new DataTools($vtools, $env['vault']->id, $dirId);

    $first = $dtools->getDockerData();
    expect($first['server_version'])->toBe('24.0.7');

    // remove the source file entirely — a second call must still succeed by
    // reading the cached .dockerData.json rather than re-parsing
    unlink("{$src}/sos_commands/docker/docker_info");

    $second = $dtools->getDockerData();
    expect($second)->toBe($first);
});

it('returns null without throwing when no docker plugin data exists at all', function () {
    $env = dockerTestEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $mount = $vtools->getMountPoint();

    $dirName = 'sosreport-host0-CASE-2026-01-01-nodocker';
    $src = "{$mount}/{$dirName}";
    mkdir($src, 0o755, true);
    file_put_contents("{$src}/uname", "Linux prod-host\n");

    $dirId = fileinode($src);
    $dtools = new DataTools($vtools, $env['vault']->id, $dirId);

    expect($dtools->getDockerData())->toBeNull();
    expect(is_file("{$src}/.dockerData.json"))->toBeFalse();
});
