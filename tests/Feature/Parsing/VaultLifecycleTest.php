<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * Vault lifecycle integration test.
 *
 * Covers the full pipeline required to exercise DataTools parsing:
 *
 *   1. Create a test user and trigger LUKS vault creation.
 *   2. Verify the vault device is the expected size.
 *   3. Login, open and mount the vault, verify it is empty.
 *   4. Upload each .gpg file from test_data/ into the vault.
 *   5. Unpack (decrypt + extract) each file and verify that the .identifier
 *      metadata file is generated in every extracted report directory.
 *   6. Run the full DataTools parsing assertion suite for every report.
 *
 * NOTE: Requires APP_NOVAULTS=FALSE and container capabilities for
 *       cryptsetup, mount, and dd.  Passphrase for all test_data files
 *       comes from env('TEST_FIXTURE_PASSPHRASE') — set it in your local
 *       .env (gitignored) or export it in CI before running phpunit.
 *
 * The production database is never touched; tests use the :memory: SQLite
 * configured in phpunit.xml.
 */

/** Vault device size for an admin user: (1000 MB disk + 100 MB adjustment) / 100 MB block = 11 blocks. */
const LIFECYCLE_EXPECTED_DEVICE_BYTES = 11 * 100 * 1024 * 1024;

/** Throwaway username used for the test vault. */
const LIFECYCLE_USERNAME = 'svtest_lifecycle';

/**
 * Return paths derived from LIFECYCLE_USERNAME.
 *
 * PHP does not allow function calls in const expressions, so we use a helper.
 *
 * @return array{hash: string, device: string, mount: string}
 */
function lifecyclePaths(): array
{
    $hash = md5(LIFECYCLE_USERNAME);

    return [
        'hash' => $hash,
        'device' => "/vault/.{$hash}.img",
        'mount' => "/vault/{$hash}",
    ];
}

/**
 * Unmount, close, and delete the test vault so every run starts from a clean
 * state.  Safe to call even when no vault exists.
 */
function cleanupLifecycleVault(): void
{
    $p = lifecyclePaths();
    exec("/bin/sudo /bin/umount {$p['mount']} 2>/dev/null");
    exec("/bin/sudo /sbin/cryptsetup luksClose {$p['hash']} 2>/dev/null");
    if (file_exists($p['device'])) {
        unlink($p['device']);
    }
    if (is_dir($p['mount'])) {
        @rmdir($p['mount']);
    }
    // Remove any stale initializeVault lock files (may be root-owned from prior runs).
    exec('/bin/sudo /bin/rm -f /var/tmp/.initializeVault_*.lock 2>/dev/null');
}

/**
 * Assert that the mandatory metadata files written by VaultTools::updateContents()
 * exist inside an extracted SOS report directory.
 */
function assertMetadataFilesExist(string $reportDir): void
{
    expect(is_dir($reportDir))->toBeTrue("Report directory does not exist: {$reportDir}");
    expect(file_exists("{$reportDir}/.identifier"))->toBeTrue(
        ".identifier file missing in {$reportDir}"
    );
}

/**
 * Run the full DataTools assertion suite against a single extracted report.
 *
 * Mirrors every assertion in DataToolsTest.php so newly extracted reports
 * receive identical coverage.
 */
function assertDataTools(DataTools $dtools): void
{
    // --- uname / kernel / OS ---
    $uname = $dtools->unameData();
    expect($uname)->not->toBeNull();
    expect((array) $uname)->toHaveKeys(['os_name', 'hostname', 'kernel_release', 'architecture']);
    expect(((array) $uname)['os_name'])->toBe('linux');

    $kver = $dtools->kernelVersion();
    expect($kver)->not->toBeNull()->toBeArray()->toHaveKeys(['kernel', 'major', 'minor']);

    expect($dtools->osVersion())->not->toBeNull()->toBeArray()->not->toBeEmpty();

    $sos = $dtools->sosVersion();
    expect($sos)->not->toBeNull();
    expect($sos->sos_version)->toBeString()->not->toBeEmpty();

    // --- host ---
    $host = $dtools->getHostData();
    expect($host)->not->toBeNull();
    expect((array) $host)->toHaveKeys(['hostname', 'uptime']);

    // --- CPU ---
    $cpu = $dtools->getCpuData();
    expect($cpu)->not->toBeNull();
    expect(isset($cpu->model))->toBeTrue();
    expect($cpu->model)->toBeString()->not->toBeEmpty();
    expect(isset($cpu->cpu))->toBeTrue();
    expect($cpu->cpu->cpu)->toBe('total');

    // --- memory ---
    $mem = $dtools->getMemoryData();
    expect($mem)->not->toBeNull();
    expect(isset($mem->memory))->toBeTrue();
    expect(isset($mem->swap))->toBeTrue();

    // --- disk ---
    $disk = $dtools->getDiskData();
    expect($disk)->not->toBeNull()->toBeArray()->not->toBeEmpty();
    expect((array) $disk[0])->toHaveKeys(['label', 'size']);

    // --- processes ---
    $procs = $dtools->getProcessesData();
    expect($procs)->not->toBeNull()->toBeArray()->not->toBeEmpty();
    $first = array_values($procs)[0];
    expect((array) $first)->toHaveKeys(['PID', 'CMD']);

    // --- network ---
    expect($dtools->getNetworkData())->not->toBeNull();
    expect($dtools->getNICData())->not->toBeNull()->toBeArray();

    // --- packages ---
    expect($dtools->getPackagesData())->not->toBeNull()->toBeArray()->not->toBeEmpty();

    // --- errors / kernel parameters ---
    expect($dtools->getErrorsData())->not->toBeNull();
    expect($dtools->getKernelParamsData())->not->toBeNull();

    // --- file reading ---
    $lines = $dtools->readFileContents('sos_commands/kernel/uname_-a');
    expect($lines)->toBeArray()->not->toBeEmpty();
    expect($lines[0])->toBeString();
    expect($dtools->readFileContents('nonexistent/path/file.txt'))->toBeNull();

    // --- inventory ---
    expect($dtools->getInventoryData())->not->toBeNull();
}

// ---------------------------------------------------------------------------
// Lifecycle test
// ---------------------------------------------------------------------------

// Safety net: clean up even when the test fails mid-way.
// Also restore APP_NOVAULTS guard so Login events in later test files do not
// trigger initializeVault for random factory users.
afterAll(function (): void {
    cleanupLifecycleVault();
    Config::set('app.vaultsDisabled', 'TRUE');
});

it('creates a vault, extracts sos reports, and validates DataTools parsing', function () {
    // Override the global APP_NOVAULTS=TRUE so VaultTools performs real OS operations.
    Config::set('app.vaultsDisabled', 'FALSE');

    $this->seed(RolesTableSeeder::class);

    $p = lifecyclePaths();

    // Ensure a clean filesystem state so the test is fully idempotent.
    cleanupLifecycleVault();

    // -----------------------------------------------------------------------
    // Step 1 — Create test user; trigger LUKS vault creation
    // -----------------------------------------------------------------------
    $user = User::factory()->create(['username' => LIFECYCLE_USERNAME]);
    $user->syncRoles(['admin']);

    // Use setUser() instead of Auth::login() to avoid firing the Login event,
    // which would trigger the initializeVault listener and create the vault
    // before our assertions run.
    $this->actingAs($user);

    $vtools = new VaultTools($user);

    expect($vtools->createVault())->toBeTrue('createVault() should succeed on a clean device path');

    // Reload VaultTools so it picks up the Vault record written to the DB.
    $vault = Vault::where('owner', $user->id)->firstOrFail();
    $vtools = new VaultTools($user, $vault->id);

    // -----------------------------------------------------------------------
    // Step 2 — Verify vault device size
    // -----------------------------------------------------------------------
    expect(file_exists($p['device']))->toBeTrue('vault device file should exist after createVault()');
    expect(filesize($p['device']))->toBe(LIFECYCLE_EXPECTED_DEVICE_BYTES);

    // -----------------------------------------------------------------------
    // Step 3 — Verify vault is open, mounted, and empty
    // -----------------------------------------------------------------------
    expect($vtools->isOpen())->toBeTrue('vault should be open immediately after createVault()');
    expect($vtools->isMounted())->toBeTrue('vault should be mounted immediately after createVault()');
    expect($vtools->getDirs())->toBeEmpty('a freshly created vault should have no extracted directories');

    // -----------------------------------------------------------------------
    // Steps 4 & 5 — Upload and unpack each .gpg file
    // -----------------------------------------------------------------------
    $mountPoint = $vtools->getMountPoint();
    $testFiles = glob(base_path('test_data/*.gpg'));

    expect($testFiles)->not->toBeEmpty('test_data/ must contain at least one .gpg file');

    $prevDirCount = 0;

    foreach ($testFiles as $src) {
        $filename = basename($src);
        $dest = "{$mountPoint}/{$filename}";

        // Upload: copy the encrypted archive into the vault mount point.
        expect(copy($src, $dest))->toBeTrue("Failed to copy {$filename} into vault");
        expect(is_file($dest))->toBeTrue("File not found at destination after copy: {$dest}");

        // Unpack: decrypt with the known passphrase and extract.
        $fid = random_int(100_000, 999_999);
        $vtools->unpack(env('TEST_FIXTURE_PASSPHRASE'), $filename, $fid);

        // Verify exactly one new directory appeared after extraction.
        $currentDirs = $vtools->getDirs();
        expect(count($currentDirs))->toBeGreaterThan(
            $prevDirCount,
            "No new directory appeared after unpacking {$filename}"
        );

        // Verify .identifier metadata file was written inside the new directory.
        $latestDir = end($currentDirs);
        assertMetadataFilesExist("{$mountPoint}/{$latestDir->name}");

        $prevDirCount = count($currentDirs);
    }

    // -----------------------------------------------------------------------
    // Step 6 — DataTools parsing assertions for every extracted report
    // -----------------------------------------------------------------------
    $allDirs = $vtools->getDirs();
    expect($allDirs)->toHaveCount(
        count($testFiles),
        'Number of extracted directories should equal the number of test .gpg files'
    );

    $vaultId = $vault->id;

    foreach ($allDirs as $dir) {
        $dtools = new DataTools($vtools, $vaultId, $dir->id);
        assertDataTools($dtools);
    }

    // Clean up so subsequent runs start fresh.
    cleanupLifecycleVault();
})->group('vault-lifecycle');
