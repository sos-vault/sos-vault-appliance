<?php

use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\Compliance\ComplianceAnalysisService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * ComplianceAnalysisService — built on the same lightweight
 * vaultsDisabled=TRUE harness as ComplianceFactExtractorTest (real files on
 * a plain temp directory, no LUKS mount).
 */

function complianceAnalysisEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-compliance-analysis-'.bin2hex(random_bytes(4));
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

function complianceAnalysisCase(array $env, string $dirName): array
{
    $src = "{$env['mount']}/{$dirName}";
    if (! is_dir($src)) {
        mkdir($src, 0o755, true);
    }

    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $dirId = fileinode($src);
    $dtools = new DataTools($vtools, $env['vault']->id, $dirId);

    $case = SupportCase::create([
        'case' => strtoupper($dirName),
        'path' => $dirName,
        'owner' => $env['user']->id,
        'group' => $env['user']->id,
        'perms' => 0o700,
        'file_id' => $dirId,
        'vault_id' => $env['vault']->id,
    ]);

    return ['vtools' => $vtools, 'dtools' => $dtools, 'src' => $src, 'case' => $case];
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-compliance-analysis-')) {
        exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
    Config::set('app.vaultsDisabled', 'TRUE');
});

it('produces a completed run with a populated summary and correctly-scoped findings across all 4 rulesets', function () {
    $env = complianceAnalysisEnv();
    $dirName = 'sosreport-host0-CASE-2026-01-01-full';
    $src = "{$env['mount']}/{$dirName}";

    mkdir("{$src}/etc/ssh/sshd_config.d", 0o755, true);
    mkdir("{$src}/etc/pam.d", 0o755, true);
    mkdir("{$src}/etc/audit", 0o755, true);
    mkdir("{$src}/etc/selinux", 0o755, true);
    mkdir("{$src}/etc/docker", 0o755, true);

    file_put_contents("{$src}/etc/os-release", "NAME=\"Red Hat Enterprise Linux\"\nID=\"rhel\"\nVERSION_ID=\"9.4\"\nPRETTY_NAME=\"Red Hat Enterprise Linux 9.4\"\n");
    file_put_contents("{$src}/etc/ssh/sshd_config", "PermitRootLogin no\nX11Forwarding no\nProtocol 2\nMaxAuthTries 4\nLogLevel INFO\n");
    file_put_contents("{$src}/etc/sudoers", "Defaults secure_path=/sbin:/bin\nDefaults use_pty\nDefaults logfile=/var/log/sudo.log\n");
    file_put_contents("{$src}/etc/selinux/config", "SELINUX=enforcing\nSELINUXTYPE=targeted\n");
    file_put_contents("{$src}/etc/audit/auditd.conf", "admin_space_left_action = halt\n");
    file_put_contents("{$src}/etc/audit/audit.rules", "-w /usr/bin/dockerd -p x -k docker\n");
    file_put_contents("{$src}/etc/docker/daemon.json", json_encode([
        'icc' => false,
        'live-restore' => true,
        'userland-proxy' => false,
        'no-new-privileges' => true,
        'log-level' => 'info',
        'log-driver' => 'json-file',
        'default-ulimits' => ['nofile' => ['Hard' => 64000, 'Name' => 'nofile', 'Soft' => 64000]],
        'insecure-registries' => [],
    ]));

    $r = complianceAnalysisCase($env, $dirName);

    $run = app(ComplianceAnalysisService::class)->analyzeCase($r['case'], $r['dtools'], $r['vtools']);

    expect($run)->toBeInstanceOf(AnalysisRun::class)
        ->and($run->status)->toBe('completed')
        ->and($run->error_message)->toBeNull()
        ->and($run->summary)->toBeArray()
        ->and(array_keys($run->summary))->toEqualCanonicalizing(['cis', 'stig', 'docker_bench', 'exposure']);

    foreach (['cis', 'stig', 'docker_bench', 'exposure'] as $ruleset) {
        expect($run->summary[$ruleset])->toHaveKeys(['pass', 'fail', 'not_applicable', 'error', 'score']);
    }

    $findings = ComplianceFinding::where('analysis_run_id', $run->id)->get();
    expect($findings)->not->toBeEmpty();

    foreach ($findings as $finding) {
        expect($finding->case_id)->toBe($r['case']->id)
            ->and($finding->vault_id)->toBe($env['vault']->id)
            ->and(in_array($finding->ruleset, ['cis', 'stig', 'docker_bench', 'exposure'], true))->toBeTrue()
            ->and(in_array($finding->status, ComplianceFinding::STATUSES, true))->toBeTrue();
    }

    // docker daemon.json was captured, so docker_bench must have evaluated
    // (not skipped as not_applicable across the board).
    $dockerBenchFindings = $findings->where('ruleset', 'docker_bench');
    expect($dockerBenchFindings->where('status', 'pass')->count())->toBeGreaterThan(0);

    // A RHEL 9.4 STIG mapping exists, so the stig ruleset must have produced rows.
    expect($findings->where('ruleset', 'stig')->count())->toBeGreaterThan(0);
});

it('completes (never fails) with an empty case directory, docker_bench entirely not_applicable', function () {
    $env = complianceAnalysisEnv();
    $dirName = 'sosreport-host0-CASE-2026-01-01-empty';
    $src = "{$env['mount']}/{$dirName}";
    mkdir($src, 0o755, true);
    file_put_contents("{$src}/uname", "Linux prod-host\n");

    $r = complianceAnalysisCase($env, $dirName);

    $run = app(ComplianceAnalysisService::class)->analyzeCase($r['case'], $r['dtools'], $r['vtools']);

    expect($run->status)->toBe('completed')
        ->and($run->error_message)->toBeNull()
        ->and($run->summary)->toBeArray();

    $findings = ComplianceFinding::where('analysis_run_id', $run->id)->get();
    expect($findings)->not->toBeEmpty();

    $dockerBenchFindings = $findings->where('ruleset', 'docker_bench');
    expect($dockerBenchFindings)->not->toBeEmpty();
    foreach ($dockerBenchFindings as $finding) {
        expect($finding->status)->toBe('not_applicable');
    }

    // No usable os_id means StigRuleset resolves no slug, producing zero rows —
    // still a completed run, just an empty ruleset.
    expect($findings->where('ruleset', 'stig'))->toBeEmpty();
});
