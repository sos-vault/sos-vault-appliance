<?php

use App\Events\AlertsEvaluationRequested;
use App\Models\Alert;
use App\Models\AlertTrigger;
use App\Models\Group;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * queue.default is 'sync' in tests, so firing AlertsEvaluationRequested runs
 * the EvaluateAlerts listener inline. Verifies the vault-wide fan-out: alerts
 * created by different members of the same group vault are ALL evaluated
 * against one newly-unpacked case.
 */

function listenerTestEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-alerts-listener-'.bin2hex(random_bytes(4));
    mkdir($root, 0o755, true);
    Config::set('filesystems.disks.vault.root', $root);
    Config::set('app.vaultsDisabled', 'TRUE');

    $manager = User::factory()->create();
    $manager->syncRoles(['admin']);

    $vname = 'tv'.bin2hex(random_bytes(3));
    $mountp = "{$root}/{$vname}";
    mkdir($mountp, 0o755, true);

    $vault = Vault::create([
        'user_vault' => $vname,
        'device' => "{$root}/.{$vname}.img",
        'header_file' => "{$root}/.headers/.{$vname}.data",
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $manager->id,
        'group' => $manager->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'description' => 'test vault',
        'current_size' => 100 * 1024 * 1024,
        'plan_size' => 100 * 1024 * 1024,
    ]);

    $group = Group::create(['name' => 'team', 'owner_id' => $manager->id, 'vault_id' => $vault->id, 'max_members' => 5]);
    $member = User::factory()->create(['group_id' => $group->id]);
    $member->syncRoles(['admin']);

    return ['manager' => $manager, 'member' => $member, 'vault' => $vault, 'mount' => $mountp, 'root' => $root];
}

function listenerMakeReport(array $env, string $dirName, array $files): array
{
    $src = "{$env['mount']}/{$dirName}";
    mkdir($src, 0o755, true);
    foreach ($files as $relative => $contents) {
        $full = "{$src}/{$relative}";
        @mkdir(dirname($full), 0o755, true);
        file_put_contents($full, $contents);
    }

    $vtools = new VaultTools($env['manager'], $env['vault']->id);
    $vtools->updateContents();
    $vtools->getContents($src);
    $did = fileinode($src);

    $case = SupportCase::create([
        'case' => strtoupper($dirName),
        'path' => $dirName,
        'owner' => $env['manager']->id,
        'group' => $env['manager']->id,
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
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-alerts-listener-')) {
        exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

it('evaluates every enabled alert in the vault, regardless of which member created it', function () {
    $env = listenerTestEnv();
    $r = listenerMakeReport($env, 'sosreport-host0-2026-01-01-a', [
        'etc/hostname' => "host0\n",
        'sos_commands/process/ps_auxww' => "root 1 init\n", // no crond
    ]);

    $managerAlert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['manager']->id,
        'name' => 'crond not running',
        'severity' => 'CRITICAL',
        'type' => 'grep',
        'grep_path' => 'sos_commands/process/ps_auxww',
        'grep_regex' => '\bcrond\b',
        'grep_match_mode' => 'not_found',
    ]);

    $memberAlert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['member']->id,
        'name' => 'hostname is host0',
        'severity' => 'INFO',
        'type' => 'grep',
        'grep_path' => 'etc/hostname',
        'grep_regex' => 'host0',
        'grep_match_mode' => 'found',
    ]);

    // Disabled alert must never fire.
    $disabledAlert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['manager']->id,
        'name' => 'disabled rule',
        'severity' => 'INFO',
        'enabled' => false,
        'type' => 'grep',
        'grep_path' => 'etc/hostname',
        'grep_regex' => 'host0',
        'grep_match_mode' => 'found',
    ]);

    event(new AlertsEvaluationRequested($env['manager']->id, $env['vault']->id, $r['did'], $r['case']->id));

    expect(AlertTrigger::where('alert_id', $managerAlert->id)->exists())->toBeTrue();
    expect(AlertTrigger::where('alert_id', $memberAlert->id)->exists())->toBeTrue();
    expect(AlertTrigger::where('alert_id', $disabledAlert->id)->exists())->toBeFalse();
});

it('does not evaluate alerts belonging to a different vault', function () {
    $env = listenerTestEnv();
    $r = listenerMakeReport($env, 'sosreport-host0-2026-01-01-a', ['etc/hostname' => "host0\n"]);

    $otherVault = Vault::create([
        'user_vault' => 'tvother'.bin2hex(random_bytes(3)),
        'device' => 'unused',
        'header_file' => 'unused',
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $env['member']->id,
        'group' => $env['member']->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'current_size' => 1,
        'plan_size' => 1,
    ]);

    $otherAlert = Alert::create([
        'vault_id' => $otherVault->id,
        'user_id' => $env['member']->id,
        'name' => 'unrelated vault alert',
        'severity' => 'INFO',
        'type' => 'grep',
        'grep_path' => 'etc/hostname',
        'grep_regex' => 'host0',
        'grep_match_mode' => 'found',
    ]);

    event(new AlertsEvaluationRequested($env['manager']->id, $env['vault']->id, $r['did'], $r['case']->id));

    expect(AlertTrigger::where('alert_id', $otherAlert->id)->exists())->toBeFalse();
});
