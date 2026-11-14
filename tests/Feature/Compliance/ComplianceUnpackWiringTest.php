<?php

use App\Events\AlertsEvaluationRequested;
use App\Events\ComplianceAnalysisRequested;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;

/*
 * Confirms VaultTools::unpack() fans out to BOTH the Alerts and Compliance
 * background pipelines after a successful extraction. Built on the same
 * lightweight vaultsDisabled=TRUE harness as the Alerts listener test, using
 * a small synthetic (non-gpg, plain tar.gz) archive rather than a real
 * multi-hundred-MB sosreport fixture, since only the dispatch itself is
 * under test here.
 */

function unpackWiringEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-compliance-unpack-'.bin2hex(random_bytes(4));
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
        'current_size' => 200 * 1024 * 1024,
        'plan_size' => 200 * 1024 * 1024,
    ]);

    return ['user' => $user, 'vault' => $vault, 'mount' => $mountp, 'root' => $root];
}

// Builds a tiny plain (non-gpg) tar.gz whose top-level directory name matches
// what VaultTools::parseFilename() derives from $filename, and drops it in
// the vault mount point ready for unpack().
function unpackWiringMakeArchive(array $env, string $dirName): string
{
    $work = "{$env['root']}/build-{$dirName}";
    mkdir("{$work}/{$dirName}/etc", 0o755, true);
    mkdir("{$work}/{$dirName}/sos_commands/process", 0o755, true);
    file_put_contents("{$work}/{$dirName}/etc/hostname", "host0\n");
    // summaryData() (called synchronously inside unpack()) reads these two
    // files to resolve sos_version->pid — without them it null-derefs before
    // this test's dispatch assertions are even reached.
    file_put_contents("{$work}/{$dirName}/version.txt", "sosreport: 4.5.0\n");
    file_put_contents("{$work}/{$dirName}/sos_commands/process/ps_auxwwwm", "root 1234 0.0 0.1 12345 6789 ? Ss 00:00 0:00 /usr/bin/sosreport\n");

    $filename = "{$dirName}.tar.gz";
    $archive = "{$env['mount']}/{$filename}";
    exec('cd '.escapeshellarg($work).' && /bin/tar -czf '.escapeshellarg($archive).' '.escapeshellarg($dirName));
    exec('/bin/rm -rf '.escapeshellarg($work));

    return $filename;
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-compliance-unpack-')) {
        exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

it('dispatches both AlertsEvaluationRequested and ComplianceAnalysisRequested after a successful unpack', function () {
    Event::fake([AlertsEvaluationRequested::class, ComplianceAnalysisRequested::class]);

    $env = unpackWiringEnv();
    Auth::guard('web')->setUser($env['user']);

    $dirName = 'sosreport-host0-2026-01-15-abc1234';
    $filename = unpackWiringMakeArchive($env, $dirName);

    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $vtools->unpack('', $filename, random_int(100_000, 999_999));

    $case = SupportCase::where('vault_id', $env['vault']->id)->first();
    expect($case)->not->toBeNull();

    Event::assertDispatched(
        AlertsEvaluationRequested::class,
        fn ($e) => (int) $e->vid === $env['vault']->id && (int) $e->cid === $case->id
    );

    Event::assertDispatched(
        ComplianceAnalysisRequested::class,
        fn ($e) => (int) $e->vid === $env['vault']->id && (int) $e->cid === $case->id
    );
});
