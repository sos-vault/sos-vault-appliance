<?php

use App\Livewire\ComplianceDashboard;
use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

/*
 * ComplianceDashboard mirrors AlertsTable's HasTable/HasActions/HasForms
 * structure but is read-only (no Create/Edit/Delete). The "run analysis now"
 * fallback runs ComplianceAnalysisService synchronously (see the component's
 * doc comment) so it needs a real vault directory, mirroring
 * ComplianceAnalysisServiceTest's harness.
 */

function complianceDashboardEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-compliance-dashboard-'.bin2hex(random_bytes(4));
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

function complianceDashboardMakeReport(array $env, string $dirName): array
{
    $src = "{$env['mount']}/{$dirName}";
    mkdir("{$src}/etc", 0o755, true);
    file_put_contents("{$src}/etc/hostname", "host0\n");

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

    return ['src' => $src, 'did' => $did, 'case' => $case];
}

function complianceDashboardMount(User $user, array $r, array $env)
{
    return Livewire::actingAs($user)->test(ComplianceDashboard::class, [
        'vid' => $env['vault']->id,
        'did' => $r['did'],
        'caseid' => $r['case']->id,
    ]);
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-compliance-dashboard-')) {
        exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

it('renders the latest run\'s findings', function () {
    $env = complianceDashboardEnv();
    $r = complianceDashboardMakeReport($env, 'sosreport-host0-2026-01-01-a');

    $run = AnalysisRun::create([
        'case_id' => $r['case']->id,
        'vault_id' => $env['vault']->id,
        'dir_id' => $r['did'],
        'status' => 'completed',
        'triggered_by' => 'unpack',
        'started_at' => now(),
        'completed_at' => now(),
        'summary' => [
            'cis' => ['pass' => 1, 'fail' => 1, 'not_applicable' => 0, 'error' => 0, 'score' => 50.0],
            'stig' => ['pass' => 0, 'fail' => 0, 'not_applicable' => 1, 'error' => 0, 'score' => null],
            'docker_bench' => ['pass' => 0, 'fail' => 0, 'not_applicable' => 1, 'error' => 0, 'score' => null],
            'exposure' => ['pass' => 1, 'fail' => 0, 'not_applicable' => 0, 'error' => 0, 'score' => 100.0],
        ],
    ]);

    $findingA = ComplianceFinding::create([
        'analysis_run_id' => $run->id,
        'case_id' => $r['case']->id,
        'vault_id' => $env['vault']->id,
        'ruleset' => 'cis',
        'rule_id' => 'CIS-1.1',
        'title' => 'Root login disabled',
        'description' => 'desc',
        'severity' => 'HIGH',
        'status' => 'pass',
        'category' => 'ssh',
        'matched_path' => 'etc/ssh/sshd_config',
        'remediation' => 'n/a',
        'reference_url' => 'https://example.com/cis-1.1',
    ]);

    $findingB = ComplianceFinding::create([
        'analysis_run_id' => $run->id,
        'case_id' => $r['case']->id,
        'vault_id' => $env['vault']->id,
        'ruleset' => 'exposure',
        'rule_id' => 'EXP-1',
        'title' => 'Open port exposed',
        'description' => 'desc',
        'severity' => 'CRITICAL',
        'status' => 'fail',
        'category' => 'network',
        'matched_path' => 'etc/services',
        'remediation' => 'close the port',
        'reference_url' => null,
    ]);

    $component = complianceDashboardMount($env['user'], $r, $env);

    $component->assertCanSeeTableRecords([$findingA, $findingB]);
    expect($component->instance()->run->id)->toBe($run->id);
});

it('filters findings by ruleset, severity, and status', function () {
    $env = complianceDashboardEnv();
    $r = complianceDashboardMakeReport($env, 'sosreport-host0-2026-01-01-b');

    $run = AnalysisRun::create([
        'case_id' => $r['case']->id,
        'vault_id' => $env['vault']->id,
        'dir_id' => $r['did'],
        'status' => 'completed',
        'triggered_by' => 'unpack',
        'started_at' => now(),
        'completed_at' => now(),
        'summary' => [],
    ]);

    $cisFail = ComplianceFinding::create([
        'analysis_run_id' => $run->id, 'case_id' => $r['case']->id, 'vault_id' => $env['vault']->id,
        'ruleset' => 'cis', 'rule_id' => 'CIS-1', 'title' => 'CIS fail', 'severity' => 'HIGH', 'status' => 'fail', 'category' => 'a',
    ]);
    $stigPass = ComplianceFinding::create([
        'analysis_run_id' => $run->id, 'case_id' => $r['case']->id, 'vault_id' => $env['vault']->id,
        'ruleset' => 'stig', 'rule_id' => 'STIG-1', 'title' => 'STIG pass', 'severity' => 'LOW', 'status' => 'pass', 'category' => 'b',
    ]);

    $component = complianceDashboardMount($env['user'], $r, $env);

    $component->filterTable('ruleset', 'cis')
        ->assertCanSeeTableRecords([$cisFail])
        ->assertCanNotSeeTableRecords([$stigPass]);

    $component->resetTableFilters()
        ->filterTable('severity', 'LOW')
        ->assertCanSeeTableRecords([$stigPass])
        ->assertCanNotSeeTableRecords([$cisFail]);

    $component->resetTableFilters()
        ->filterTable('status', 'fail')
        ->assertCanSeeTableRecords([$cisFail])
        ->assertCanNotSeeTableRecords([$stigPass]);
});

it('has no create, edit, or delete actions anywhere in the table', function () {
    $env = complianceDashboardEnv();
    $r = complianceDashboardMakeReport($env, 'sosreport-host0-2026-01-01-c');

    $run = AnalysisRun::create([
        'case_id' => $r['case']->id,
        'vault_id' => $env['vault']->id,
        'dir_id' => $r['did'],
        'status' => 'completed',
        'triggered_by' => 'unpack',
        'started_at' => now(),
        'completed_at' => now(),
        'summary' => [],
    ]);

    $finding = ComplianceFinding::create([
        'analysis_run_id' => $run->id, 'case_id' => $r['case']->id, 'vault_id' => $env['vault']->id,
        'ruleset' => 'cis', 'rule_id' => 'CIS-1', 'title' => 'CIS fail', 'severity' => 'HIGH', 'status' => 'fail', 'category' => 'a',
    ]);

    $component = complianceDashboardMount($env['user'], $r, $env);

    $component->assertTableActionDoesNotExist('create');
    $component->assertTableActionDoesNotExist('edit', record: $finding);
    $component->assertTableActionDoesNotExist('delete', record: $finding);
});

it('runs a synchronous analysis on demand when no run exists yet for the case', function () {
    $env = complianceDashboardEnv();
    $r = complianceDashboardMakeReport($env, 'sosreport-host0-2026-01-01-d');

    expect(AnalysisRun::where('case_id', $r['case']->id)->exists())->toBeFalse();

    $component = complianceDashboardMount($env['user'], $r, $env);

    $component->assertTableActionExists('runAnalysisNow');
    $component->callTableAction('runAnalysisNow');

    $run = AnalysisRun::where('case_id', $r['case']->id)->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('completed');

    expect($component->instance()->run->id)->toBe($run->id);
});
