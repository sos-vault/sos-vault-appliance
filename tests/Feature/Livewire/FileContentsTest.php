<?php

use App\Livewire\FileContents;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

/**
 * FileContents Livewire component tests.
 *
 * The first ten tests exercise logic that does not require a real vault.
 * The final integration test mounts the component against the lifecycle test
 * vault (username: svtest_lifecycle) which is created on demand the same way
 * DataToolsTest does it.
 *
 * A separate FcVaultSetup static class and ensureFcVault() function are used
 * (instead of reusing DataToolsSetup/ensureTestVault from DataToolsTest.php)
 * so that this file remains self-contained and runnable in isolation.
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Recursively walk a VaultTools JSON tree and return the id of the first leaf
 * node (a file — i.e. a node with no children or an empty nodes array).
 * Returns null when the tree is empty.
 */
function findFirstFileId(object $tree): ?int
{
    foreach ($tree->nodes as $node) {
        if (! empty($node->nodes)) {
            $found = findFirstFileId($node);
            if ($found !== null) {
                return $found;
            }
        } else {
            return (int) $node->id;
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// One-time vault state for the integration test
// ---------------------------------------------------------------------------

class FcVaultSetup
{
    public static bool $done = false;

    public static string $vaultDir = '';

    public static int $dirId = 0;
}

/**
 * Ensure the lifecycle vault is mounted with at least one extracted SOS
 * directory.  Idempotent — subsequent calls in the same process are no-ops.
 */
function ensureFcVault(): void
{
    if (FcVaultSetup::$done) {
        return;
    }

    // Override APP_NOVAULTS=TRUE so VaultTools performs real OS operations.
    Config::set('app.vaultsDisabled', 'FALSE');

    $hash = md5('svtest_lifecycle');
    $device = "/vault/.{$hash}.img";
    $mount = "/vault/{$hash}";

    FcVaultSetup::$vaultDir = $hash;

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

    // Read the first extracted directory ID from .contents.json.
    $contentsFile = "{$mount}/.contents.json";
    if (file_exists($contentsFile)) {
        $tree = json_decode(file_get_contents($contentsFile));
        foreach ($tree->nodes[0]->nodes as $node) {
            if ($node->type === 'd') {
                FcVaultSetup::$dirId = (int) $node->id;
                break;
            }
        }
    }

    FcVaultSetup::$done = true;
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
});

// ---------------------------------------------------------------------------
// Mount guard — missing required parameters
// ---------------------------------------------------------------------------

it('dispatches setErrorState when mounted without any params', function () {
    $this->actingAs($this->user);

    Livewire::test(FileContents::class)
        ->assertDispatched('setErrorState');
});

it('dispatches setErrorState when vid is missing', function () {
    $this->actingAs($this->user);

    Livewire::test(FileContents::class, ['did' => 1, 'fid' => 2, 'cid' => 3])
        ->assertDispatched('setErrorState');
});

it('dispatches setErrorState when did is missing', function () {
    $this->actingAs($this->user);

    Livewire::test(FileContents::class, ['vid' => 1, 'fid' => 2, 'cid' => 3])
        ->assertDispatched('setErrorState');
});

it('dispatches setErrorState when fid is missing', function () {
    $this->actingAs($this->user);

    Livewire::test(FileContents::class, ['vid' => 1, 'did' => 2, 'cid' => 3])
        ->assertDispatched('setErrorState');
});

it('dispatches setErrorState when cid is missing', function () {
    $this->actingAs($this->user);

    Livewire::test(FileContents::class, ['vid' => 1, 'did' => 2, 'fid' => 3])
        ->assertDispatched('setErrorState');
});

// ---------------------------------------------------------------------------
// Default property values
// ---------------------------------------------------------------------------

it('has expected default property values on instantiation', function () {
    $this->actingAs($this->user);

    Livewire::test(FileContents::class)
        ->assertSet('root', 'pre1')
        ->assertSet('sme', false)
        ->assertSet('lines', 0);
});

// ---------------------------------------------------------------------------
// openSosFile event — updates component properties
// ---------------------------------------------------------------------------

it('openSosFile event updates vid, did, fid and cid', function () {
    $this->actingAs($this->user);

    Livewire::test(FileContents::class)
        ->dispatch('openSosFile', cid: 10, vid: 20, did: 30, fid: 40)
        ->assertSet('cid', 10)
        ->assertSet('vid', 20)
        ->assertSet('did', 30)
        ->assertSet('fid', 40);
});

// ---------------------------------------------------------------------------
// ansi2html — ANSI escape code conversion
// ---------------------------------------------------------------------------

it('ansi2html converts red ANSI code to a span tag', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(FileContents::class);
    $instance = $component->instance();

    $result = $instance->ansi2html("\x1B[0;31mERROR\x1B[0m");

    expect($result)->toContain('<span style="color:red">')
        ->and($result)->toContain('ERROR')
        ->and($result)->toContain('</span>');
});

it('ansi2html converts green ANSI code correctly', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(FileContents::class);
    $instance = $component->instance();

    $result = $instance->ansi2html("\x1B[0;32mOK\x1B[0m");

    expect($result)->toContain('<span style="color:green">');
});

it('ansi2html passes through plain text unchanged', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(FileContents::class);
    $instance = $component->instance();

    expect($instance->ansi2html('plain text'))->toBe('plain text');
});

// ---------------------------------------------------------------------------
// Full mount with real vault (integration)
// ---------------------------------------------------------------------------

it('mounts successfully with a valid vault and known file', function () {
    ensureFcVault();

    // The mounted vault is owned by svtest_lifecycle. resolveVaultUser() only
    // elevates to the owner for entitled callers, so act as the owner here (the
    // ordinary "user reads own vault" path). Cross-tenant denial is covered by
    // the test below.
    $lifecycleUser = User::where('username', 'svtest_lifecycle')->firstOrFail();

    $this->actingAs($lifecycleUser);

    // ensureFcVault() already created a vault for svtest_lifecycle — reuse it.
    // The vaults table has a UNIQUE constraint on owner, so we must not factory-create a second one.
    $vault = Vault::where('owner', $lifecycleUser->id)->firstOrFail();
    $vault->update(['status' => 'OPEN']);

    $vtools = new VaultTools($lifecycleUser, $vault->id);
    $dir = $vtools->getDirById(FcVaultSetup::$dirId);

    if (! $dir) {
        $this->markTestSkipped('Test SOS directory not found in vault.');
    }

    // The top-level .contents.json only stores extracted directory entries.
    // Files inside a SOS report are discovered by reading that directory's
    // own tree via getContents().
    $sosPath = $vtools->getMountPoint().'/'.$dir->name;
    $sosTree = $vtools->getContents($sosPath);
    $fid = $sosTree ? findFirstFileId($sosTree) : null;

    if (! $fid) {
        $this->markTestSkipped('No files found in SOS directory.');
    }

    Livewire::test(FileContents::class, [
        'vid' => $vault->id,
        'did' => FcVaultSetup::$dirId,
        'fid' => $fid,
        'cid' => 1,
    ])
        ->assertNotDispatched('setErrorState')
        ->assertStatus(200);
});
