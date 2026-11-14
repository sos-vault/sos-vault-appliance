<?php

use App\Models\Alert;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\AlertEvaluationService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * Exercises AlertEvaluationService's grep / levels / diff logic against a
 * real, unencrypted VaultTools mount (the same no-LUKS temp-dir harness used
 * by FixSosHtmlTest), so DataTools::readFileContents()/getDiskData() run
 * against real files instead of mocks.
 */

function alertsTestEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-alerts-'.bin2hex(random_bytes(4));
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

/** Lay down a minimal unpacked report directory named $dirName with the given files. */
function alertsMakeReport(array $env, string $dirName, array $files): array
{
    $mount = $env['mount'];
    $src = "{$mount}/{$dirName}";
    mkdir($src, 0o755, true);

    foreach ($files as $relative => $contents) {
        $full = "{$src}/{$relative}";
        @mkdir(dirname($full), 0o755, true);
        file_put_contents($full, $contents);
    }

    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $vtools->updateContents();
    $vtools->getContents($src);

    $did = fileinode($src);

    $case = SupportCase::create([
        'case' => strtoupper($dirName),
        'path' => $dirName,
        'owner' => $env['user']->id,
        'group' => $env['user']->id,
        'perms' => 0o700,
        'file_id' => $did,
        'vault_id' => $env['vault']->id,
    ]);

    return ['vtools' => $vtools, 'src' => $src, 'did' => $did, 'case' => $case];
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->service = app(AlertEvaluationService::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-alerts-')) {
        exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

// ── Grep ──────────────────────────────────────────────────────────────────

it('fires a "found" grep alert when the regex matches a line', function () {
    $env = alertsTestEnv();
    $r = alertsMakeReport($env, 'sosreport-host0-2026-01-01-a', [
        'etc/ssh/sshd_config' => "Port 22\nPermitRootLogin no\n",
    ]);
    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'root login',
        'severity' => 'WARNING',
        'type' => 'grep',
        'grep_path' => 'etc/ssh/sshd_config',
        'grep_regex' => 'PermitRootLogin\s+no',
        'grep_match_mode' => 'found',
    ]);

    $detail = $this->service->evaluateGrep($dtools, $alert);

    expect($detail)->not->toBeNull();
    expect($detail['matched_line'])->toContain('PermitRootLogin');
});

it('does not fire a "found" grep alert when the regex is absent', function () {
    $env = alertsTestEnv();
    $r = alertsMakeReport($env, 'sosreport-host0-2026-01-01-a', [
        'sos_commands/process/ps_auxww' => "root 1 init\nroot 2 kthreadd\n",
    ]);
    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'crond running',
        'severity' => 'WARNING',
        'type' => 'grep',
        'grep_path' => 'sos_commands/process/ps_auxww',
        'grep_regex' => '\bcrond\b',
        'grep_match_mode' => 'found',
    ]);

    expect($this->service->evaluateGrep($dtools, $alert))->toBeNull();
});

it('fires a "not_found" grep alert when the expected pattern is absent — process not running', function () {
    $env = alertsTestEnv();
    $r = alertsMakeReport($env, 'sosreport-host0-2026-01-01-a', [
        'sos_commands/process/ps_auxww' => "root 1 init\nroot 2 kthreadd\n",
    ]);
    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'crond running',
        'severity' => 'CRITICAL',
        'type' => 'grep',
        'grep_path' => 'sos_commands/process/ps_auxww',
        'grep_regex' => '\bcrond\b',
        'grep_match_mode' => 'not_found',
    ]);

    $detail = $this->service->evaluateGrep($dtools, $alert);

    expect($detail)->not->toBeNull();
    expect($detail['reason'])->toBe('pattern absent');
});

it('does not fire a "not_found" grep alert when the pattern IS present', function () {
    $env = alertsTestEnv();
    $r = alertsMakeReport($env, 'sosreport-host0-2026-01-01-a', [
        'sos_commands/process/ps_auxww' => "root 1 init\nroot 900 crond\n",
    ]);
    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'crond running',
        'severity' => 'CRITICAL',
        'type' => 'grep',
        'grep_path' => 'sos_commands/process/ps_auxww',
        'grep_regex' => '\bcrond\b',
        'grep_match_mode' => 'not_found',
    ]);

    expect($this->service->evaluateGrep($dtools, $alert))->toBeNull();
});

it('skips a grep alert whose file is missing from the report, in either match mode', function () {
    $env = alertsTestEnv();
    $r = alertsMakeReport($env, 'sosreport-host0-2026-01-01-a', [
        'etc/hostname' => "host0\n",
    ]);
    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'missing file',
        'severity' => 'WARNING',
        'type' => 'grep',
        'grep_path' => 'etc/does/not/exist.conf',
        'grep_regex' => 'anything',
        'grep_match_mode' => 'not_found',
    ]);

    expect($this->service->evaluateGrep($dtools, $alert))->toBeNull();
});

// ── Levels ────────────────────────────────────────────────────────────────

it('fires a disk-usage levels alert for the matching mountpoint over threshold', function () {
    $env = alertsTestEnv();
    $r = alertsMakeReport($env, 'sosreport-host0-2026-01-01-a', [
        'sos_commands/filesys/df_-al_-x_autofs' => sprintf(
            "%-20s %10s %10s %10s %5s %s\n",
            'Filesystem', '1K-blocks', 'Used', 'Available', 'Use%', 'Mounted on'
        )."/dev/sda1              10485760    9986048     499712   96% /\n",
        'sos_commands/filesys/findmnt' => "TARGET SOURCE FSTYPE OPTIONS\n/ /dev/sda1 ext4 rw\n",
        'sos_commands/block/lsblk_-O_-P' => 'NAME="sda1" MOUNTPOINT="/" FSTYPE="ext4"'."\n",
    ]);
    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'disk full',
        'severity' => 'ERROR',
        'type' => 'levels',
        'levels_metric' => 'disk',
        'levels_threshold' => 90,
        'levels_mount_path' => '/',
    ]);

    $detail = $this->service->evaluateLevels($dtools, $alert);

    expect($detail)->not->toBeNull();
    expect($detail['measured'])->toBeGreaterThanOrEqual(90);
});

it('does not fire a disk-usage levels alert for a different mountpoint', function () {
    $env = alertsTestEnv();
    $r = alertsMakeReport($env, 'sosreport-host0-2026-01-01-a', [
        'sos_commands/filesys/df_-al_-x_autofs' => sprintf(
            "%-20s %10s %10s %10s %5s %s\n",
            'Filesystem', '1K-blocks', 'Used', 'Available', 'Use%', 'Mounted on'
        )."/dev/sda1              10485760    9986048     499712   96% /var\n",
        'sos_commands/filesys/findmnt' => "TARGET SOURCE FSTYPE OPTIONS\n/var /dev/sda1 ext4 rw\n",
        'sos_commands/block/lsblk_-O_-P' => 'NAME="sda1" MOUNTPOINT="/var" FSTYPE="ext4"'."\n",
    ]);
    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'disk full',
        'severity' => 'ERROR',
        'type' => 'levels',
        'levels_metric' => 'disk',
        'levels_threshold' => 90,
        'levels_mount_path' => '/',
    ]);

    expect($this->service->evaluateLevels($dtools, $alert))->toBeNull();
});

// ── Diff vs reference case ───────────────────────────────────────────────

it('fires a diff alert when the file content differs from the reference case', function () {
    $env = alertsTestEnv();
    $ref = alertsMakeReport($env, 'sosreport-host0-2026-01-01-ref', [
        'etc/sudoers' => "root ALL=(ALL) ALL\n",
    ]);
    $cur = alertsMakeReport($env, 'sosreport-host0-2026-02-01-cur', [
        'etc/sudoers' => "root ALL=(ALL) ALL\n%wheel ALL=(ALL) NOPASSWD: ALL\n",
    ]);
    $dtools = new DataTools($cur['vtools'], $env['vault']->id, $cur['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'sudoers changed',
        'severity' => 'CRITICAL',
        'type' => 'diff',
        'diff_path' => 'etc/sudoers',
        'diff_reference_case_id' => $ref['case']->id,
    ]);

    $detail = $this->service->evaluateDiff($dtools, $alert, $cur['case'], $cur['vtools']);

    expect($detail)->not->toBeNull();
    expect($detail['old_checksum'])->not->toBe($detail['new_checksum']);
});

it('does not fire a diff alert when the file is unchanged from the reference case', function () {
    $env = alertsTestEnv();
    $contents = "root ALL=(ALL) ALL\n";
    $ref = alertsMakeReport($env, 'sosreport-host0-2026-01-01-ref', ['etc/sudoers' => $contents]);
    $cur = alertsMakeReport($env, 'sosreport-host0-2026-02-01-cur', ['etc/sudoers' => $contents]);
    $dtools = new DataTools($cur['vtools'], $env['vault']->id, $cur['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'sudoers changed',
        'severity' => 'CRITICAL',
        'type' => 'diff',
        'diff_path' => 'etc/sudoers',
        'diff_reference_case_id' => $ref['case']->id,
    ]);

    expect($this->service->evaluateDiff($dtools, $alert, $cur['case'], $cur['vtools']))->toBeNull();
});

it('fires a diff alert when the file exists only on one side', function () {
    $env = alertsTestEnv();
    $ref = alertsMakeReport($env, 'sosreport-host0-2026-01-01-ref', []);
    $cur = alertsMakeReport($env, 'sosreport-host0-2026-02-01-cur', ['etc/sudoers.d/custom' => "new\n"]);
    $dtools = new DataTools($cur['vtools'], $env['vault']->id, $cur['did']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['user']->id,
        'name' => 'new sudoers.d file',
        'severity' => 'WARNING',
        'type' => 'diff',
        'diff_path' => 'etc/sudoers.d/custom',
        'diff_reference_case_id' => $ref['case']->id,
    ]);

    expect($this->service->evaluateDiff($dtools, $alert, $cur['case'], $cur['vtools']))->not->toBeNull();
});
