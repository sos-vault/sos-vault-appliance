<?php

use App\Models\Module;
use App\Services\PackageManager;
use Illuminate\Support\Facades\File;

// ---------------------------------------------------------------------------
// Helper: create a temporary GPG home with a no-passphrase signing key
// ---------------------------------------------------------------------------

/**
 * Returns the path to a temp GPG home directory with a signing-capable key.
 * Creates the key on first call and reuses it within the same test run.
 */
function testGpgHome(): string
{
    static $gpgHome = null;

    if ($gpgHome !== null && is_dir($gpgHome)) {
        return $gpgHome;
    }

    $gpgHome = sys_get_temp_dir().'/sos-vault-test-gpg-'.getmypid();
    @mkdir($gpgHome, 0700, true);

    $keyParams = <<<'GPG'
        %no-protection
        Key-Type: EDDSA
        Key-Curve: ed25519
        Subkey-Type: ECDH
        Subkey-Curve: cv25519
        Name-Real: SOS Vault Test
        Name-Email: test@sos-vault.local
        Expire-Date: 0
        %commit
        GPG;

    $paramsFile = $gpgHome.'/keygen-params.txt';
    file_put_contents($paramsFile, $keyParams);

    exec(
        "gpg --homedir {$gpgHome} --batch --yes --no-tty --gen-key {$paramsFile} 2>&1",
        $output,
        $exitCode
    );

    if ($exitCode !== 0 || ! isGpgHomeReady($gpgHome)) {
        $gpgHome = null;

        return '';
    }

    return $gpgHome;
}

function isGpgHomeReady(string $gpgHome): bool
{
    $output = shell_exec("gpg --homedir {$gpgHome} --list-secret-keys 2>/dev/null");

    return ! empty(trim($output ?? ''));
}

// ---------------------------------------------------------------------------
// Helper: build and GPG-sign a .tar.gz into a .tar.gz.gpg
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $manifest
 */
function buildSignedPackage(string $gpgOutputPath, array $manifest, string $gpgHome): void
{
    $id = $manifest['id'];

    $tmpDir = sys_get_temp_dir()."/gpg-build-{$id}-".uniqid();
    $pkgDir = "{$tmpDir}/{$id}";
    mkdir($pkgDir, 0755, true);
    file_put_contents("{$pkgDir}/manifest.json", json_encode($manifest));

    $tarPath = sys_get_temp_dir()."/gpg-pkg-{$id}-".uniqid().'.tar';
    $tarGzPath = $tarPath.'.gz';

    $archive = new PharData($tarPath);
    $archive->buildFromDirectory($tmpDir);
    $archive->compress(Phar::GZ);
    unset($archive);

    @unlink($tarPath);
    File::deleteDirectory($tmpDir);

    $cmd = "gpg --homedir {$gpgHome} --batch --yes --no-tty --pinentry-mode loopback --passphrase '' --output {$gpgOutputPath} --sign {$tarGzPath} 2>&1";
    exec($cmd, $output, $exitCode);

    @unlink($tarGzPath);

    if ($exitCode !== 0) {
        throw new RuntimeException('GPG sign failed: '.implode("\n", $output));
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

afterEach(function () {
    foreach (glob(base_path('modules/test-gpg-*')) as $dir) {
        File::deleteDirectory($dir);
    }
});

it('installs a GPG-signed .tar.gz.gpg module package', function () {
    $gpgHome = testGpgHome();

    $gpgPath = sys_get_temp_dir().'/test-gpg-module-'.uniqid().'.tar.gz.gpg';

    $manifest = [
        'type' => 'module',
        'id' => 'test-gpg-install',
        'name' => 'GPG Test Module',
        'version' => '1.0.0',
        'provider' => null,
        'tool' => null,
    ];

    buildSignedPackage($gpgPath, $manifest, $gpgHome);

    // Point the app config to the test GPG home (it only has the public key there)
    config(['modules.gpg_home' => $gpgHome]);

    $module = app(PackageManager::class)->install($gpgPath);

    expect($module)->toBeInstanceOf(Module::class)
        ->and($module->module_id)->toBe('test-gpg-install')
        ->and($module->name)->toBe('GPG Test Module')
        ->and($module->version)->toBe('1.0.0');

    expect(Module::where('module_id', 'test-gpg-install')->exists())->toBeTrue();

    @unlink($gpgPath);
})->skip(fn () => ! isGpgHomeReady(testGpgHome()), 'Could not create a test GPG key');

it('throws RuntimeException when given a .gpg file with invalid GPG home', function () {
    config(['modules.gpg_home' => '/tmp/nonexistent-gnupg-'.uniqid()]);

    expect(fn () => app(PackageManager::class)->install('/tmp/fake.tar.gz.gpg'))
        ->toThrow(RuntimeException::class, 'GPG home directory not found');
});
