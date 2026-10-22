<?php

use App\Events\ComplianceAnalysisRequested;
use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * queue.default is 'sync' in tests, so firing ComplianceAnalysisRequested
 * runs the RunComplianceAnalysis listener inline. Mirrors
 * EvaluateAlertsListenerTest's harness.
 */

function complianceListenerEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-compliance-listener-'.bin2hex(random_bytes(4));
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

function complianceListenerMakeReport(array $env, string $dirName): array
{
    $src = "{$env['mount']}/{$dirName}";
    mkdir("{$src}/etc", 0o755, true);
    file_put_contents("{$src}/etc/hostname", "host0\n");

    $vtools = new VaultTools($env['user'], $env['vault']->id);
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
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-compliance-listener-')) {
        exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

it('persists an AnalysisRun and findings when the event fires for a valid open vault + case', function () {
    $env = complianceListenerEnv();
    $r = complianceListenerMakeReport($env, 'sosreport-host0-2026-01-01-a');

    event(new ComplianceAnalysisRequested($env['user']->id, $env['vault']->id, $r['did'], $r['case']->id));

    $run = AnalysisRun::where('case_id', $r['case']->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('completed');

    expect(ComplianceFinding::where('analysis_run_id', $run->id)->count())->toBeGreaterThan(0);
});

it('does nothing and does not throw when the case does not exist', function () {
    $env = complianceListenerEnv();

    event(new ComplianceAnalysisRequested($env['user']->id, $env['vault']->id, 999_999, 999_999));

    expect(AnalysisRun::count())->toBe(0);
});

it('does nothing and does not throw when the user does not exist', function () {
    $env = complianceListenerEnv();
    $r = complianceListenerMakeReport($env, 'sosreport-host0-2026-01-01-b');

    event(new ComplianceAnalysisRequested(999_999, $env['vault']->id, $r['did'], $r['case']->id));

    expect(AnalysisRun::count())->toBe(0);
});

it('does nothing and does not throw when the vault id does not match the resolved vault', function () {
    $env = complianceListenerEnv();
    $r = complianceListenerMakeReport($env, 'sosreport-host0-2026-01-01-c');

    $otherUser = User::factory()->create();
    $otherVault = Vault::create([
        'user_vault' => 'tvother'.bin2hex(random_bytes(3)),
        'device' => 'unused',
        'header_file' => 'unused',
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $otherUser->id,
        'group' => $otherUser->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'current_size' => 1,
        'plan_size' => 1,
    ]);

    event(new ComplianceAnalysisRequested($env['user']->id, $otherVault->id, $r['did'], $r['case']->id));

    expect(AnalysisRun::count())->toBe(0);
});
