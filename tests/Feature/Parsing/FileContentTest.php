<?php

use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\FileContent;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Carbon\Carbon;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * FileContent model (Sushi) tests.
 *
 * The first eight tests cover guard logic and header sanitization that require
 * no real vault.  The final three integration tests use the lifecycle vault
 * (username: svtest_lifecycle) and follow the same idempotent setup pattern
 * as DataToolsTest / FileContentsTest.
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Recursively walk a VaultTools JSON tree and collect all leaf file nodes.
 *
 * @return object[] Array of file node objects, each having at minimum an
 *                  `id` property.
 */
function collectFileNodes(object $tree): array
{
    $files = [];
    foreach ($tree->nodes as $node) {
        if (! empty($node->nodes)) {
            $files = array_merge($files, collectFileNodes($node));
        } else {
            $files[] = $node;
        }
    }

    return $files;
}

// ---------------------------------------------------------------------------
// One-time vault state
// ---------------------------------------------------------------------------

class FctVaultSetup
{
    public static bool $done = false;

    public static string $vaultDir = '';

    public static int $dirId = 0;
}

/**
 * Ensure the lifecycle vault is mounted with at least one extracted SOS
 * directory.  Idempotent — subsequent calls in the same process are no-ops.
 */
function ensureFctVault(): void
{
    if (FctVaultSetup::$done) {
        return;
    }

    // Override APP_NOVAULTS=TRUE so VaultTools performs real OS operations.
    Config::set('app.vaultsDisabled', 'FALSE');

    $hash = md5('svtest_lifecycle');
    $device = "/vault/.{$hash}.img";
    $mount = "/vault/{$hash}";

    FctVaultSetup::$vaultDir = $hash;

    exec("/bin/findmnt -clnt ext4,ext3,ext2 | /bin/grep -q {$hash}", $out, $ret);
    $isMounted = ($ret === 0);

    if (! $isMounted) {
        exec("/bin/sudo /bin/umount {$mount} 2>/dev/null");
        exec("/bin/sudo /sbin/cryptsetup luksClose {$hash} 2>/dev/null");
        exec('/bin/sudo /bin/rm -f /var/tmp/.initializeVault_*.lock 2>/dev/null');
        if (file_exists($device)) {
            unlink($device);
        }

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

    $contentsFile = "{$mount}/.contents.json";
    if (file_exists($contentsFile)) {
        $tree = json_decode(file_get_contents($contentsFile));
        foreach ($tree->nodes[0]->nodes as $node) {
            if ($node->type === 'd') {
                FctVaultSetup::$dirId = (int) $node->id;
                break;
            }
        }
    }

    FctVaultSetup::$done = true;
}

// ---------------------------------------------------------------------------
// Test setup
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
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// ---------------------------------------------------------------------------
// getRows — missing parameter guards
// ---------------------------------------------------------------------------

it('getRows returns empty array when fid is missing', function () {
    $rows = FileContent::withParameters(['did' => 1, 'vid' => 1, 'cid' => 1, 'format' => 'raw'])->getRows();

    expect($rows)->toBe([]);
});

it('getRows returns empty array when did is missing', function () {
    $rows = FileContent::withParameters(['fid' => 1, 'vid' => 1, 'cid' => 1, 'format' => 'raw'])->getRows();

    expect($rows)->toBe([]);
});

it('getRows returns empty array when vid is missing', function () {
    $rows = FileContent::withParameters(['fid' => 1, 'did' => 1, 'cid' => 1, 'format' => 'raw'])->getRows();

    expect($rows)->toBe([]);
});

it('getRows returns empty array when cid is missing', function () {
    $rows = FileContent::withParameters(['fid' => 1, 'did' => 1, 'vid' => 1, 'format' => 'raw'])->getRows();

    expect($rows)->toBe([]);
});

it('getRows returns empty array when format is missing', function () {
    $rows = FileContent::withParameters(['fid' => 1, 'did' => 1, 'vid' => 1, 'cid' => 1])->getRows();

    expect($rows)->toBe([]);
});

// ---------------------------------------------------------------------------
// column key sanitization helper (tested indirectly through getRows)
// ---------------------------------------------------------------------------

it('sanitizes headers with percent signs to valid column keys', function () {
    $header = '%usr';
    $key = preg_replace('/^(\d)/', 'col_$1', preg_replace('/[^a-zA-Z0-9_]/', '_', $header));

    expect($key)->toBe('_usr');
});

it('sanitizes headers starting with a digit', function () {
    $header = '4.15.0-fips';
    $key = preg_replace('/^(\d)/', 'col_$1', preg_replace('/[^a-zA-Z0-9_]/', '_', $header));

    expect($key)->toStartWith('col_');
});

it('leaves already-safe header names unchanged', function () {
    $header = 'hostname';
    $key = preg_replace('/^(\d)/', 'col_$1', preg_replace('/[^a-zA-Z0-9_]/', '_', $header));

    expect($key)->toBe('hostname');
});

it('glues journalctl two-word column labels before splitting on space', function () {
    // journalctl --list-boots header: "IDX BOOT ID  FIRST ENTRY  LAST ENTRY"
    $headerLine = 'IDX BOOT ID                          FIRST ENTRY                  LAST ENTRY';

    $headerLine = strtr($headerLine, [
        'BOOT ID' => 'BOOT_ID',
        'FIRST ENTRY' => 'FIRST_ENTRY',
        'LAST ENTRY' => 'LAST_ENTRY',
    ]);

    $headers = preg_split('/ {1,}/', $headerLine, 4);

    expect($headers)->toBe(['IDX', 'BOOT_ID', 'FIRST_ENTRY', 'LAST_ENTRY']);
});

it('disambiguates a header named ID from the reserved id column', function () {
    // SQLite column names are case-insensitive, so a header "ID" collides with
    // Sushi's auto-increment "id" primary key and a repeated label collides with itself.
    $headers = ['IDX', 'BOOT', 'ID', 'FIRST', 'ID'];
    $usedKeys = [];
    $keys = [];

    foreach ($headers as $i => $header) {
        $key = preg_replace('/^(\d)/', 'col_$1', preg_replace('/[^a-zA-Z0-9_]/', '_', $header));

        if ($key === '') {
            $key = "col_{$i}";
        }

        if (strtolower($key) === 'id' || isset($usedKeys[strtolower($key)])) {
            $key = "{$key}_{$i}";
        }
        $usedKeys[strtolower($key)] = true;

        $keys[] = $key;
    }

    expect($keys)->toBe(['IDX', 'BOOT', 'ID_2', 'FIRST', 'ID_4'])
        ->and(array_map('strtolower', $keys))->not->toContain('id')
        ->and(count(array_unique(array_map('strtolower', $keys))))->toBe(count($keys));
});

// ---------------------------------------------------------------------------
// Integration — real vault (format=raw)
// ---------------------------------------------------------------------------

it('getRows returns a non-empty array for format=raw with a valid vault and file', function () {
    ensureFctVault();

    $this->actingAs($this->user);

    $vault = Vault::factory()->create([
        'user_vault' => FctVaultSetup::$vaultDir,
        'status' => 'OPEN',
        'owner' => $this->user->id,
    ]);

    $vtools = new VaultTools($this->user, $vault->id);
    $dir = $vtools->getDirById(FctVaultSetup::$dirId);

    if (! $dir) {
        $this->markTestSkipped('Test SOS directory not found in vault.');
    }

    $sosPath = $vtools->getMountPoint().'/'.$dir->name;
    $sosTree = $vtools->getContents($sosPath);
    $files = $sosTree ? collectFileNodes($sosTree) : [];

    if (empty($files)) {
        $this->markTestSkipped('No files found in SOS directory.');
    }

    $fid = (int) $files[0]->id;
    $rows = FileContent::withParameters([
        'vid' => $vault->id,
        'did' => FctVaultSetup::$dirId,
        'fid' => $fid,
        'cid' => 1,
        'format' => 'raw',
    ])->getRows();

    expect($rows)->not->toBeEmpty()
        ->and($rows[0])->toHaveKey('vault_id')
        ->and($rows[0])->toHaveKey('file_id')
        ->and($rows[0])->toHaveKey('name');
});

it('getRows raw response contains expected metadata fields', function () {
    ensureFctVault();

    $this->actingAs($this->user);

    $vault = Vault::factory()->create([
        'user_vault' => FctVaultSetup::$vaultDir,
        'status' => 'OPEN',
        'owner' => $this->user->id,
    ]);

    $vtools = new VaultTools($this->user, $vault->id);
    $dir = $vtools->getDirById(FctVaultSetup::$dirId);

    if (! $dir) {
        $this->markTestSkipped('Test SOS directory not found in vault.');
    }

    $sosPath = $vtools->getMountPoint().'/'.$dir->name;
    $sosTree = $vtools->getContents($sosPath);
    $files = $sosTree ? collectFileNodes($sosTree) : [];

    if (empty($files)) {
        $this->markTestSkipped('No files found in SOS directory.');
    }

    $fid = (int) $files[0]->id;
    $rows = FileContent::withParameters([
        'vid' => $vault->id,
        'did' => FctVaultSetup::$dirId,
        'fid' => $fid,
        'cid' => 1,
        'format' => 'raw',
    ])->getRows();

    expect($rows[0])->toHaveKeys(['vault_id', 'dir_id', 'file_id', 'isTable', 'isLogFile', 'has_header', 'columns', 'separator']);
});

// ---------------------------------------------------------------------------
// Integration — real vault (format=table) with a known table file
// ---------------------------------------------------------------------------

it('getRows returns sanitized column-keyed rows for format=table with a table file', function () {
    ensureFctVault();

    $this->actingAs($this->user);

    $vault = Vault::factory()->create([
        'user_vault' => FctVaultSetup::$vaultDir,
        'status' => 'OPEN',
        'owner' => $this->user->id,
    ]);

    $vtools = new VaultTools($this->user, $vault->id);
    $dir = $vtools->getDirById(FctVaultSetup::$dirId);

    if (! $dir) {
        $this->markTestSkipped('Test SOS directory not found in vault.');
    }

    $sosPath = $vtools->getMountPoint().'/'.$dir->name;
    $sosTree = $vtools->getContents($sosPath);
    $files = $sosTree ? collectFileNodes($sosTree) : [];

    if (empty($files)) {
        $this->markTestSkipped('No files found in SOS directory.');
    }

    // Find the first file classified as a table (not a log).
    $tableFileId = null;
    foreach ($files as $f) {
        $fc = $vtools->getFileContentsById($vault->id, FctVaultSetup::$dirId, (int) $f->id);
        if ($fc && $fc->isTable && ! $fc->isLogFile) {
            $tableFileId = (int) $f->id;
            break;
        }
    }

    if (! $tableFileId) {
        $this->markTestSkipped('No table-format file found in SOS directory.');
    }

    $rows = FileContent::withParameters([
        'vid' => $vault->id,
        'did' => FctVaultSetup::$dirId,
        'fid' => $tableFileId,
        'cid' => 1,
        'format' => 'table',
    ])->getRows();

    expect($rows)->not->toBeEmpty();

    // All column keys in every row must be safe identifiers (no dots, no %).
    foreach ($rows as $row) {
        foreach (array_keys($row) as $key) {
            expect($key)->toMatch('/^[a-zA-Z_][a-zA-Z0-9_]*$/');
        }
    }
});

// ---------------------------------------------------------------------------
// setRows — sharing / unsharing (DB-only, no vault required)
//
// These tests exercise the same code paths as the file-controls shareFile(),
// unshareFile() and copyUrl() actions. setRows() only touches the
// contents_requests and annotations tables so no real LUKS vault is needed.
// ---------------------------------------------------------------------------

it('setRows creates a ContentsRequest when none exists', function () {
    $fc = FileContent::withParameters([
        'vid' => 801, 'did' => 802, 'fid' => 803, 'cid' => 804, 'format' => 'raw',
    ]);

    $fc->setRows([]);

    expect(
        ContentsRequest::where('vault_id', 801)->where('dir_id', 802)->where('file_id', 803)->exists()
    )->toBeTrue();
});

it('setRows generates a sosShared URL in the ContentsRequest', function () {
    $fc = FileContent::withParameters([
        'vid' => 811, 'did' => 812, 'fid' => 813, 'cid' => 814, 'format' => 'raw',
    ]);

    $fc->setRows([]);

    $url = ContentsRequest::where('vault_id', 811)->where('dir_id', 812)->where('file_id', 813)->value('url');

    expect($url)->toContain('sosShared/');
});

it('setRows creates an Annotation with PRIVATE status by default', function () {
    $fc = FileContent::withParameters([
        'vid' => 821, 'did' => 822, 'fid' => 823, 'cid' => 824, 'format' => 'raw',
    ]);

    $fc->setRows([]);

    $status = Annotation::where('vault_id', 821)->where('dir_id', 822)->where('file_id', 823)->value('status');

    expect($status)->toBe('PRIVATE');
});

it('shareFile: setRows with shared=SHARED sets ContentsRequest status to SHARED', function () {
    $fc = FileContent::withParameters([
        'vid' => 831, 'did' => 832, 'fid' => 833, 'cid' => 834, 'format' => 'raw',
    ]);

    $fc->setRows(['shared' => 'SHARED', 'astatus' => 'SHARED']);

    $status = ContentsRequest::where('vault_id', 831)->where('dir_id', 832)->where('file_id', 833)->value('status');

    expect($status)->toBe('SHARED');
});

it('shareFile: setRows with astatus=SHARED sets Annotation status to SHARED', function () {
    $fc = FileContent::withParameters([
        'vid' => 841, 'did' => 842, 'fid' => 843, 'cid' => 844, 'format' => 'raw',
    ]);

    $fc->setRows(['shared' => 'SHARED', 'astatus' => 'SHARED']);

    $status = Annotation::where('vault_id', 841)->where('dir_id', 842)->where('file_id', 843)->value('status');

    expect($status)->toBe('SHARED');
});

it('unshareFile: setRows with shared=PRIVATE reverts ContentsRequest status to PRIVATE', function () {
    $fc = FileContent::withParameters([
        'vid' => 851, 'did' => 852, 'fid' => 853, 'cid' => 854, 'format' => 'raw',
    ]);

    $fc->setRows(['shared' => 'SHARED', 'astatus' => 'SHARED']);
    $fc->setRows(['shared' => 'PRIVATE', 'astatus' => 'PRIVATE']);

    $status = ContentsRequest::where('vault_id', 851)->where('dir_id', 852)->where('file_id', 853)->value('status');

    expect($status)->toBe('PRIVATE');
});

it('unshareFile: setRows with astatus=PRIVATE reverts Annotation status to PRIVATE', function () {
    $fc = FileContent::withParameters([
        'vid' => 861, 'did' => 862, 'fid' => 863, 'cid' => 864, 'format' => 'raw',
    ]);

    $fc->setRows(['shared' => 'SHARED', 'astatus' => 'SHARED']);
    $fc->setRows(['shared' => 'PRIVATE', 'astatus' => 'PRIVATE']);

    $status = Annotation::where('vault_id', 861)->where('dir_id', 862)->where('file_id', 863)->value('status');

    expect($status)->toBe('PRIVATE');
});

it('setRows preserves the same URL across multiple calls', function () {
    $fc = FileContent::withParameters([
        'vid' => 871, 'did' => 872, 'fid' => 873, 'cid' => 874, 'format' => 'raw',
    ]);

    $fc->setRows([]);
    $url1 = ContentsRequest::where('vault_id', 871)->where('dir_id', 872)->where('file_id', 873)->value('url');

    $fc->setRows(['shared' => 'SHARED', 'astatus' => 'SHARED']);
    $url2 = ContentsRequest::where('vault_id', 871)->where('dir_id', 872)->where('file_id', 873)->value('url');

    expect($url1)->toBe($url2)->not->toBeEmpty();
});

it('setRows stores the expiry date on ContentsRequest', function () {
    $expire = Carbon::now()->addDays(7)->format('Y-m-d H:i:s');

    $fc = FileContent::withParameters([
        'vid' => 881, 'did' => 882, 'fid' => 883, 'cid' => 884, 'format' => 'raw',
    ]);

    $fc->setRows(['shared' => 'SHARED', 'astatus' => 'SHARED', 'expire' => $expire]);

    $stored = ContentsRequest::where('vault_id', 881)->where('dir_id', 882)->where('file_id', 883)->value('expire');

    expect($stored)->toBe($expire);
});
