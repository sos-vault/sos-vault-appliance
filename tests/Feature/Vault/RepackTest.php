<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * Repack tests — exercise VaultTools::repack() against a real temp directory
 * acting as the vault mount point. APP_NOVAULTS=TRUE keeps cryptsetup/mount
 * out of the loop; the test still runs real tar / gpg via the user's sudo,
 * which is what production www-data has after the docker-compose sudoers
 * update bundled in this change.
 */

function repackTempRoot(): string
{
    $root = sys_get_temp_dir().'/sos-vault-repack-test-'.bin2hex(random_bytes(4));
    mkdir($root, 0o755, true);

    return $root;
}

function buildVaultEnv(): array
{
    $root = repackTempRoot();
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

function makeFakeSosreport(string $mount, string $name, array $extraFiles = []): string
{
    $dir = "{$mount}/{$name}";
    mkdir($dir, 0o755, true);
    mkdir("{$dir}/sos_commands/sos_extras", 0o755, true);
    file_put_contents("{$dir}/uname", "Linux test-host 6.1.0\n");
    file_put_contents("{$dir}/hostname", "test-host\n");
    file_put_contents("{$dir}/version.txt", "sosreport v4.0\n");

    // EXTRAS symlink — must be excluded by the exclusion list.
    @symlink('sos_commands/sos_extras', "{$dir}/EXTRAS");

    // Metadata sidecars — must be excluded by the exclusion list.
    file_put_contents("{$dir}/.identifier", "ID\n");
    file_put_contents("{$dir}/.contents.json", '{}');
    file_put_contents("{$dir}/.sos.json", '{}');
    file_put_contents("{$dir}/.uname.json", '{}');

    foreach ($extraFiles as $rel => $content) {
        $path = "{$dir}/{$rel}";
        @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $content);
    }

    return $dir;
}

function tarListing(string $archive, ?string $passphrase = null): array
{
    if ($passphrase !== null && $passphrase !== '') {
        $tmpPass = tempnam('/var/tmp', 'sos-test-pass-');
        file_put_contents($tmpPass, $passphrase);
        chmod($tmpPass, 0o600);
        $cmd = sprintf(
            '/bin/gpg --decrypt --batch --pinentry-mode loopback --no-tty --passphrase-file %s %s 2>/dev/null | /bin/tar -t 2>/dev/null',
            escapeshellarg($tmpPass),
            escapeshellarg($archive),
        );
        exec($cmd, $out, $ret);
        @unlink($tmpPass);
    } else {
        exec('/bin/tar -tf '.escapeshellarg($archive).' 2>/dev/null', $out, $ret);
    }

    return $ret === 0 ? $out : [];
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-repack-test-')) {
        exec('/bin/sudo /bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

it('repacks a directory into an xz archive when no passphrase is provided', function () {
    $env = buildVaultEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);

    $dirName = 'sosreport-test-host-2026-01-01-aaaaaaa';
    makeFakeSosreport($env['mount'], $dirName);

    $result = $vtools->repack($dirName, null, 'xz');

    expect($result['ok'])->toBeTrue($result['message'] ?? 'repack failed');
    expect($result['file'])->toBe("{$dirName}.tar.xz");
    expect(is_file("{$env['mount']}/{$dirName}.tar.xz"))->toBeTrue('archive should exist at vault root');
    expect(is_file("{$env['mount']}/{$dirName}.tar.xz.gpg"))->toBeFalse('no .gpg variant when passphrase is empty');
});

it('repacks a directory into an xz.gpg archive when a passphrase is provided', function () {
    $env = buildVaultEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);

    $dirName = 'sosreport-test-host-2026-01-01-bbbbbbb';
    makeFakeSosreport($env['mount'], $dirName);

    $result = $vtools->repack($dirName, 'unit-test-pass', 'xz');

    expect($result['ok'])->toBeTrue($result['message'] ?? 'repack failed');
    expect($result['file'])->toBe("{$dirName}.tar.xz.gpg");
    expect(is_file("{$env['mount']}/{$dirName}.tar.xz.gpg"))->toBeTrue('encrypted archive should exist');
    expect(is_file("{$env['mount']}/{$dirName}.tar.xz"))->toBeFalse('plain archive should not be left behind');
});

it('defaults to xz when the compression argument is empty', function () {
    $env = buildVaultEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);

    $dirName = 'sosreport-default-2026-01-01-ccccccc';
    makeFakeSosreport($env['mount'], $dirName);

    $result = $vtools->repack($dirName, null, '');

    expect($result['ok'])->toBeTrue();
    expect($result['file'])->toBe("{$dirName}.tar.xz");
});

it('honours gz compression', function () {
    $env = buildVaultEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);

    $dirName = 'sosreport-gz-2026-01-01-ddddddd';
    makeFakeSosreport($env['mount'], $dirName);

    $result = $vtools->repack($dirName, null, 'gz');

    expect($result['ok'])->toBeTrue();
    expect($result['file'])->toBe("{$dirName}.tar.gz");
    expect(is_file("{$env['mount']}/{$dirName}.tar.gz"))->toBeTrue();
});

it('excludes EXTRAS symlink and metadata sidecars from the archive', function () {
    $env = buildVaultEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);

    $dirName = 'sosreport-excludes-2026-01-01-eeeeeee';
    makeFakeSosreport($env['mount'], $dirName);

    $result = $vtools->repack($dirName, null, 'xz');
    expect($result['ok'])->toBeTrue();

    $entries = tarListing("{$env['mount']}/{$dirName}.tar.xz");
    expect($entries)->not->toBeEmpty();

    foreach (['EXTRAS', '.identifier', '.contents.json', '.sos.json', '.uname.json'] as $excluded) {
        $matches = array_filter($entries, fn (string $e): bool => str_contains($e, $excluded));
        expect($matches)->toBeEmpty("archive should not contain {$excluded}, found: ".implode(',', $matches));
    }

    // Sanity check — real content should still be inside.
    $kept = array_filter($entries, fn (string $e): bool => str_ends_with($e, 'uname') || str_ends_with($e, 'hostname'));
    expect($kept)->not->toBeEmpty('archive should still contain non-excluded files');
});

it('aborts with a conflict message when the target archive already exists', function () {
    $env = buildVaultEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);

    $dirName = 'sosreport-conflict-2026-01-01-fffffff';
    makeFakeSosreport($env['mount'], $dirName);

    // First call succeeds.
    expect($vtools->repack($dirName, null, 'xz')['ok'])->toBeTrue();

    // Second call must refuse to overwrite.
    $second = $vtools->repack($dirName, null, 'xz');
    expect($second['ok'])->toBeFalse();
    expect($second['message'])->toBe(__('vault.repack_conflict'));
});

it('treats the encrypted variant as a conflict with the plain target', function () {
    $env = buildVaultEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);

    $dirName = 'sosreport-mixed-2026-01-01-ggggggg';
    makeFakeSosreport($env['mount'], $dirName);

    // Drop a stub encrypted variant in place; plain repack must abort.
    file_put_contents("{$env['mount']}/{$dirName}.tar.xz.gpg", 'stub');

    $result = $vtools->repack($dirName, null, 'xz');
    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toBe(__('vault.repack_conflict'));
});

it('returns dir_not_found when the directory does not exist', function () {
    $env = buildVaultEnv();
    $vtools = new VaultTools($env['user'], $env['vault']->id);

    $result = $vtools->repack('does-not-exist', null, 'xz');
    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toBe(__('vault.dir_not_found'));
});
