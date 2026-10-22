<?php

use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\DataTools;
use App\Providers\SosServiceProvider;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * DataTools parsing tests.
 *
 * Exercises every DataTools method against a real extracted SOS report.  The
 * test vault is the one created by VaultLifecycleTest (username: svtest_lifecycle,
 * vault dir: md5('svtest_lifecycle')).
 *
 * Setup strategy
 * ──────────────
 * The static class DataToolsSetup tracks whether the LUKS vault is ready.  The
 * first buildTools() call in a test run performs the one-time filesystem
 * operations (createVault, copy + unpack .gpg files) then stores the vault dir
 * hash and the first extracted dir ID in static properties.
 *
 * Because RefreshDatabase resets the :memory: SQLite between tests, each test
 * creates its own lightweight User + Vault factory records that point at the
 * already-mounted vault directory.  The filesystem state (mounted LUKS volume,
 * extracted SOS directories, .contents.json) is untouched between tests.
 *
 * The vault is NOT cleaned up at the end of this file so that the mount stays
 * active for the full test run.  VaultLifecycleTest performs its own cleanup.
 */

// ---------------------------------------------------------------------------
// One-time vault setup state
// ---------------------------------------------------------------------------

class DataToolsSetup
{
    public static bool $done = false;

    /** md5 hash of the lifecycle username — used as the vault directory name. */
    public static string $vaultDir = '';

    /** Dir ID of the first extracted SOS report, read from .contents.json. */
    public static int $dirId = 0;
}

/**
 * Ensure the lifecycle test vault exists and is mounted, with at least one
 * extracted SOS report directory.  Idempotent: subsequent calls are no-ops.
 *
 * Must be called from inside an it() closure so that RefreshDatabase has
 * already migrated the in-memory SQLite database.
 */
function ensureTestVault(): void
{
    if (DataToolsSetup::$done) {
        return;
    }

    // Override APP_NOVAULTS=TRUE so VaultTools performs real OS operations.
    Config::set('app.vaultsDisabled', 'FALSE');

    $hash = md5('svtest_lifecycle');
    $device = "/vault/.{$hash}.img";
    $mount = "/vault/{$hash}";

    DataToolsSetup::$vaultDir = $hash;

    // Determine current vault state.
    exec("/bin/findmnt -clnt ext4,ext3,ext2 | /bin/grep -q {$hash}", $out, $ret);
    $isMounted = ($ret === 0);

    if (! $isMounted) {
        // Tear down any orphaned partial state, then create fresh.
        exec("/bin/sudo /bin/umount {$mount} 2>/dev/null");
        exec("/bin/sudo /sbin/cryptsetup luksClose {$hash} 2>/dev/null");
        exec('/bin/sudo /bin/rm -f /var/tmp/.initializeVault_*.lock 2>/dev/null');
        if (file_exists($device)) {
            unlink($device);
        }

        // actingAs avoids triggering the initializeVault Login listener.
        $user = User::factory()->create(['username' => 'svtest_lifecycle']);
        $user->syncRoles(['admin']);
        Auth::guard('web')->setUser($user);

        $vtools = new VaultTools($user);
        $vtools->createVault();

        $vault = Vault::where('owner', $user->id)->firstOrFail();
        $vtools = new VaultTools($user, $vault->id);

        $mountPoint = $vtools->getMountPoint();
        foreach (glob(base_path('test_data/*.gpg')) as $src) {
            $filename = basename($src);
            copy($src, "{$mountPoint}/{$filename}");
            $vtools->unpack(env('TEST_FIXTURE_PASSPHRASE'), $filename, random_int(100_000, 999_999));
        }
    }

    // Read the first extracted directory ID from .contents.json.
    $contentsFile = "{$mount}/.contents.json";
    if (file_exists($contentsFile)) {
        $tree = json_decode(file_get_contents($contentsFile));
        foreach ($tree->nodes[0]->nodes as $node) {
            if ($node->type === 'd') {
                DataToolsSetup::$dirId = (int) $node->id;
                break;
            }
        }
    }

    DataToolsSetup::$done = true;
}

// ---------------------------------------------------------------------------
// Per-test helpers
// ---------------------------------------------------------------------------

/**
 * Build a VaultTools + DataTools pair pointing at the lifecycle vault's first
 * extracted SOS report.
 *
 * @return array{vtools: VaultTools, dtools: DataTools}
 */
function buildTools(): array
{
    ensureTestVault();

    $user = User::factory()->create();

    $vault = Vault::factory()->create([
        'user_vault' => DataToolsSetup::$vaultDir,
        'status' => 'OPEN',
        'owner' => $user->id,
    ]);

    $vtools = new VaultTools($user, $vault->id);
    $dtools = new DataTools($vtools, $vault->id, DataToolsSetup::$dirId);

    return compact('vtools', 'dtools');
}

// ---------------------------------------------------------------------------
// Test suite — one beforeEach seeds roles (reset by RefreshDatabase)
// ---------------------------------------------------------------------------

// Tear down the shared lifecycle vault after all tests in this file complete.
// Also restore the APP_NOVAULTS guard so Login events in later test files do not
// trigger initializeVault for random factory users.
afterAll(function (): void {
    $hash = md5('svtest_lifecycle');
    exec("/bin/sudo /bin/umount /vault/{$hash} 2>/dev/null");
    exec("/bin/sudo /sbin/cryptsetup luksClose {$hash} 2>/dev/null");
    exec('/bin/sudo /bin/rm -f /var/tmp/.initializeVault_*.lock 2>/dev/null');
    $device = "/vault/.{$hash}.img";
    if (file_exists($device)) {
        unlink($device);
    }
    if (is_dir("/vault/{$hash}")) {
        @rmdir("/vault/{$hash}");
    }
    Config::set('app.vaultsDisabled', 'TRUE');
});

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

// ---------------------------------------------------------------------------
// VaultTools
// ---------------------------------------------------------------------------

it('resolves the mount point for the test vault', function () {
    ['vtools' => $vtools] = buildTools();

    expect($vtools->getMountPoint())->toBe('/vault/'.DataToolsSetup::$vaultDir);
});

it('finds the test directory by id', function () {
    ['vtools' => $vtools] = buildTools();

    $dir = $vtools->getDirById(DataToolsSetup::$dirId);

    expect($dir)->not->toBeNull()
        ->and($dir->type)->toBe('d')
        ->and($dir->name)->toBeString()->not->toBeEmpty();
});

it('returns null for a non-existent directory id', function () {
    ['vtools' => $vtools] = buildTools();

    expect($vtools->getDirById(999999999))->toBeNull();
});

it('lists top-level directories in the vault', function () {
    ['vtools' => $vtools] = buildTools();

    $dirs = $vtools->getDirs();

    expect($dirs)->toBeArray()->not->toBeEmpty();
    expect(collect($dirs)->pluck('type')->unique()->toArray())->toEqual(['d']);
});

it('returns vault name', function () {
    ['vtools' => $vtools] = buildTools();

    expect($vtools->getVaultName())->toBe(DataToolsSetup::$vaultDir);
});

// ---------------------------------------------------------------------------
// DataTools — uname / kernel / OS
// ---------------------------------------------------------------------------

it('parses uname data and returns expected keys', function () {
    ['dtools' => $dtools] = buildTools();

    $uname = $dtools->unameData();

    expect($uname)->not->toBeNull()
        ->and((array) $uname)->toHaveKeys(['os_name', 'hostname', 'kernel_release', 'architecture']);
    expect(((array) $uname)['os_name'])->toBe('linux');
});

it('returns kernel version array with expected keys', function () {
    ['dtools' => $dtools] = buildTools();

    $version = $dtools->kernelVersion();

    expect($version)->not->toBeNull()->toBeArray()
        ->and($version)->toHaveKeys(['kernel', 'major', 'minor']);
});

it('returns OS version array with distribution info', function () {
    ['dtools' => $dtools] = buildTools();

    $os = $dtools->osVersion();

    expect($os)->not->toBeNull()->toBeArray()->not->toBeEmpty();
});

it('returns sos version object with version string', function () {
    ['dtools' => $dtools] = buildTools();

    $sos = $dtools->sosVersion();

    expect($sos)->not->toBeNull()
        ->and($sos->sos_version)->toBeString()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// DataTools — host
// ---------------------------------------------------------------------------

it('returns host data with expected structure', function () {
    ['dtools' => $dtools] = buildTools();

    $host = $dtools->getHostData();

    expect($host)->not->toBeNull()
        ->and((array) $host)->toHaveKeys(['hostname', 'uptime']);
});

it('persists machine_id and hostname on the case via summaryData', function () {
    ['dtools' => $dtools] = buildTools();

    $case = SupportCase::factory()->create([
        'machine_id' => null,
        'hostname' => null,
    ]);

    $dtools->summaryData($case->id);

    $host = $dtools->getHostData();
    $case->refresh();

    expect($case->hostname)->toBe($host->hostname);
    if (! empty($host->machineid)) {
        expect($case->machine_id)->toBe($host->machineid);
    } else {
        expect($case->machine_id)->toBeNull();
    }
});

// ---------------------------------------------------------------------------
// DataTools — CPU
// ---------------------------------------------------------------------------

it('returns CPU data object with model name', function () {
    ['dtools' => $dtools] = buildTools();

    $cpu = $dtools->getCpuData();

    expect($cpu)->not->toBeNull()
        ->and(isset($cpu->model))->toBeTrue()
        ->and($cpu->model)->toBeString()->not->toBeEmpty();
});

it('CPU data contains a total entry for aggregate stats', function () {
    ['dtools' => $dtools] = buildTools();

    $cpu = $dtools->getCpuData();

    expect(isset($cpu->cpu))->toBeTrue()
        ->and($cpu->cpu->cpu)->toBe('total');
});

// ---------------------------------------------------------------------------
// DataTools — Memory
// ---------------------------------------------------------------------------

it('returns memory data with memory and swap sections', function () {
    ['dtools' => $dtools] = buildTools();

    $mem = $dtools->getMemoryData();

    expect($mem)->not->toBeNull()
        ->and(isset($mem->memory))->toBeTrue();
});

it('memory data includes swap information', function () {
    ['dtools' => $dtools] = buildTools();

    $mem = $dtools->getMemoryData();

    expect(isset($mem->swap))->toBeTrue();
});

// ---------------------------------------------------------------------------
// DataTools — Disk
// ---------------------------------------------------------------------------

it('returns disk data as a non-empty array', function () {
    ['dtools' => $dtools] = buildTools();

    $disk = $dtools->getDiskData();

    expect($disk)->not->toBeNull()->toBeArray()->not->toBeEmpty();
});

it('disk entries have label and size fields', function () {
    ['dtools' => $dtools] = buildTools();

    $disk = $dtools->getDiskData();
    $first = (array) $disk[0];

    expect($first)->toHaveKeys(['label', 'size']);
});

// ---------------------------------------------------------------------------
// DataTools — Processes
// ---------------------------------------------------------------------------

it('returns process list as a non-empty array', function () {
    ['dtools' => $dtools] = buildTools();

    $procs = $dtools->getProcessesData();

    expect($procs)->not->toBeNull()->toBeArray()->not->toBeEmpty();
});

it('process entries contain PID and CMD fields', function () {
    ['dtools' => $dtools] = buildTools();

    $procs = $dtools->getProcessesData();
    $first = array_values($procs)[0];

    expect((array) $first)->toHaveKeys(['PID', 'CMD']);
});

// ---------------------------------------------------------------------------
// DataTools — Network
// ---------------------------------------------------------------------------

it('returns network data', function () {
    ['dtools' => $dtools] = buildTools();

    $net = $dtools->getNetworkData();

    expect($net)->not->toBeNull();
});

it('returns NIC data array', function () {
    ['dtools' => $dtools] = buildTools();

    $nics = $dtools->getNICData();

    expect($nics)->not->toBeNull()->toBeArray();
});

// ---------------------------------------------------------------------------
// DataTools — Packages
// ---------------------------------------------------------------------------

it('returns packages data as array', function () {
    ['dtools' => $dtools] = buildTools();

    $packages = $dtools->getPackagesData();

    expect($packages)->not->toBeNull()->toBeArray()->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// DataTools — Errors / Kernel params
// ---------------------------------------------------------------------------

it('returns errors data', function () {
    ['dtools' => $dtools] = buildTools();

    $errors = $dtools->getErrorsData();

    expect($errors)->not->toBeNull();
});

it('returns kernel parameters data', function () {
    ['dtools' => $dtools] = buildTools();

    $params = $dtools->getKernelParamsData();

    expect($params)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// DataTools — Systemd
// ---------------------------------------------------------------------------

it('parses every listed unit including failed services', function () {
    ['dtools' => $dtools] = buildTools();

    $systemd = $dtools->getSystemdData();

    if (! isset($systemd['systemd']) || empty($systemd['systemd'])) {
        $this->markTestSkipped('SOS fixture has no sos_commands/systemd/systemctl_list-units.');
    }

    expect($systemd)->toBeArray()->toHaveKey('systemd');
    expect((array) $systemd['systemd'][0])
        ->toHaveKeys(['unit', 'type', 'loaded', 'active', 'sub', 'job', 'description']);

    $records = $systemd['systemd'];
    $transitional = ['activating', 'deactivating', 'reloading'];

    foreach ($records as $record) {
        expect($record['unit'])->not->toBe('');
        expect($record['active'])->not->toBe('');

        // JOB is only populated for transitional states; a job on any other
        // state means a description word was wrongly shifted into the column
        if (! in_array(strtolower($record['active']), $transitional, true)) {
            expect($record['job'])->toBe('');
        }
    }

    $raw = $dtools->readFileContents('sos_commands/systemd/systemctl_list-units');

    // the parsed count must match the "N loaded units listed" footer — proves
    // no unit rows (e.g. failed services) are silently dropped by the parser
    $listed = null;
    foreach ($raw as $line) {
        if (preg_match('/(\d+) loaded units listed/', $line, $m)) {
            $listed = (int) $m[1];
            break;
        }
    }

    if ($listed !== null) {
        expect(count($records))->toBe($listed);
    }

    // any unit the report marks as failed / transitional must survive parsing —
    // the marker is "●" under UTF-8 or its ASCII fallback "*" under LANG=C, and
    // these rows (often failed services) are exactly what originally went missing
    $markedUnits = [];
    foreach ($raw as $line) {
        if (preg_match('/^\s*[\x{25CF}*]\s+/u', $line)) {
            $stripped = trim(preg_replace('/^\s*[\x{25CF}*]\s+/u', '', $line));
            $unit = preg_split('/\s+/', $stripped)[0] ?? null;
            if ($unit) {
                $markedUnits[] = $unit;
            }
        }
    }

    if ($markedUnits) {
        $parsedUnits = array_column($records, 'unit');
        foreach ($markedUnits as $unit) {
            expect($parsedUnits)->toContain($unit);
        }
    }
});

// ---------------------------------------------------------------------------
// SosServiceProvider — Systemd badge aggregation
// ---------------------------------------------------------------------------

it('builds the systemd summary badge with total-units headline and chart', function () {
    ['vtools' => $vtools] = buildTools();

    $user = User::factory()->create();
    $user->syncRoles(['admin']);
    Auth::guard('web')->setUser($user);

    $vault = Vault::factory()->create([
        'user_vault' => DataToolsSetup::$vaultDir,
        'status' => 'OPEN',
        'owner' => $user->id,
    ]);

    $vtools = new VaultTools($user, $vault->id);
    $sos = new SosServiceProvider($vtools, $vault->id, DataToolsSetup::$dirId, 0);

    $summary = $sos->getSummary();

    if (! isset($summary->systemd)) {
        $this->markTestSkipped('SOS fixture has no systemd data.');
    }

    $systemd = $summary->systemd;

    expect($systemd->badgeData->color)->toBeIn(['primary', 'warning', 'danger']);
    expect((array) $systemd->tableData1->data)->not->toBeEmpty();
    expect($systemd->tableData1->data[0])->toHaveKey('typecount');
    expect($systemd->badgeData->chart['series'][0]->data)->not->toBeEmpty();

    // the badge headline counts every unit, so it must match the table row count
    $rowCount = count((array) $systemd->tableData1->data);
    expect($systemd->badgeData->subTitle)->toContain((string) $rowCount);
    expect($systemd->badgeData->subTitle)->toContain('units');

    // the subtitle wording must reflect the badge state
    if ($systemd->badgeData->color === 'danger') {
        expect($systemd->badgeData->subTitle)->toContain('failed');
    } elseif ($systemd->badgeData->color === 'warning') {
        expect($systemd->badgeData->subTitle)->toContain('in transition');
    } else {
        expect($systemd->badgeData->subTitle)->toContain('all active');
    }

    // each record carries a per-type failed tally used to colour the group label;
    // it must be > 0 exactly for the types that contain a failed unit
    $records = array_map(fn ($r) => (array) $r, (array) $systemd->tableData1->data);
    $failedTypes = [];
    foreach ($records as $record) {
        expect($record)->toHaveKey('typefailed');
        if (strtolower($record['active']) === 'failed' || strtolower($record['sub']) === 'failed') {
            $failedTypes[$record['type']] = true;
        }
    }

    foreach ($records as $record) {
        if (isset($failedTypes[$record['type']])) {
            expect($record['typefailed'])->toBeGreaterThan(0);
        } else {
            expect((int) $record['typefailed'])->toBe(0);
        }
    }
});

// ---------------------------------------------------------------------------
// DataTools — File reading
// ---------------------------------------------------------------------------

it('reads a known SOS file and returns an array of lines', function () {
    ['dtools' => $dtools] = buildTools();

    $lines = $dtools->readFileContents('sos_commands/kernel/uname_-a');

    expect($lines)->toBeArray()->not->toBeEmpty()
        ->and($lines[0])->toBeString();
});

it('returns null for a non-existent file path', function () {
    ['dtools' => $dtools] = buildTools();

    $result = $dtools->readFileContents('nonexistent/path/file.txt');

    expect($result)->toBeNull();
});

it('resolves a known SOS file path to its tree node id', function () {
    ['dtools' => $dtools] = buildTools();

    $fid = $dtools->getFileIdByPath('sos_commands/kernel/uname_-a');

    expect($fid)->not->toBeNull()
        ->and((int) $fid)->toBeGreaterThan(0);
});

it('returns a null fid for a non-existent file path', function () {
    ['dtools' => $dtools] = buildTools();

    expect($dtools->getFileIdByPath('nonexistent/path/file.txt'))->toBeNull();
});

// ---------------------------------------------------------------------------
// DataTools — Inventory
// ---------------------------------------------------------------------------

it('returns inventory data', function () {
    ['dtools' => $dtools] = buildTools();

    $inventory = $dtools->getInventoryData();

    expect($inventory)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// DataTools — AI health digest
// ---------------------------------------------------------------------------

it('builds a compact AI health digest with the expected sections', function () {
    ['dtools' => $dtools] = buildTools();

    $digest = $dtools->getAiDigest();

    expect($digest)->toBeArray()
        ->toHaveKeys([
            'host', 'load', 'cpu', 'memory', 'swap', 'disks_full', 'disks_inode_full',
            'log_issues', 'failed_units', 'top_cpu', 'top_mem', 'tasks', 'nics_down', 'flags',
        ])
        ->and($digest['host'])->toHaveKeys(['hostname', 'os', 'kernel', 'cores'])
        ->and($digest['log_issues'])->toHaveKeys(['oom', 'critical', 'error', 'by_file']);
});

it('writes the digest as .aiDigest.json next to the report', function () {
    ['vtools' => $vtools, 'dtools' => $dtools] = buildTools();

    $dtools->getAiDigest();

    $dir = $vtools->getDirById(DataToolsSetup::$dirId);
    $path = $vtools->getMountPoint().'/'.$dir->name.'/.aiDigest.json';

    expect(is_file($path))->toBeTrue();
    $decoded = json_decode(file_get_contents($path), true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded)->toHaveKey('flags');
});

it('reports the heaviest processes by CPU in the digest, sorted descending', function () {
    ['dtools' => $dtools] = buildTools();

    $digest = $dtools->getAiDigest();

    expect($digest['top_cpu'])->toBeArray()->not->toBeEmpty();

    $cpuValues = array_column($digest['top_cpu'], 'cpu_pct');
    $sorted = $cpuValues;
    rsort($sorted);
    expect($cpuValues)->toBe($sorted);

    expect($digest['top_mem'])->toBeArray()->not->toBeEmpty();
    $rssValues = array_column($digest['top_mem'], 'rss_bytes');
    $sortedRss = $rssValues;
    rsort($sortedRss);
    expect($rssValues)->toBe($sortedRss);
});
