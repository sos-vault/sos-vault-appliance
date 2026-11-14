<?php

use App\Services\GpgService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Exception\InvalidOptionException;

// ---------------------------------------------------------------------------
// Real-gpg tests: a throwaway, passphrase-protected keyring mirroring the
// production layout — ed25519 primary [SC], cv25519 [E] subkey and a newer
// ed25519 [S] subkey (the licence-signing subkey).
// ---------------------------------------------------------------------------

const GPG_SERVICE_TEST_PASSPHRASE = 'correct horse battery staple';

/**
 * @return array{home: string, primary: string, signSub: string}
 */
function gpgServiceTestKeyring(): array
{
    $home = sys_get_temp_dir().'/sos-vault-gpgsvc-test-'.getmypid().'-'.uniqid();
    mkdir($home, 0700, true);

    $pass = escapeshellarg(GPG_SERVICE_TEST_PASSPHRASE);
    $base = 'gpg --homedir '.escapeshellarg($home)." --batch --no-tty --pinentry-mode loopback --passphrase {$pass}";

    exec("{$base} --quick-gen-key 'GpgService Test <gpgsvc@sos-vault.local>' ed25519 sign,cert never 2>&1", $out, $rc);
    expect($rc)->toBe(0);

    $primary = gpgServiceTestFingerprints($home)[0];

    exec("{$base} --quick-add-key {$primary} cv25519 encr never 2>&1", $out, $rc);
    expect($rc)->toBe(0);
    // Same-second creation would make "newest subkey" ambiguous for gpg.
    sleep(1);
    exec("{$base} --quick-add-key {$primary} ed25519 sign never 2>&1", $out, $rc);
    expect($rc)->toBe(0);

    $fprs = gpgServiceTestFingerprints($home);

    return ['home' => $home, 'primary' => $primary, 'signSub' => end($fprs)];
}

/**
 * @return array<int, string>
 */
function gpgServiceTestFingerprints(string $home): array
{
    $lines = shell_exec('gpg --homedir '.escapeshellarg($home).' --batch --with-colons --list-keys 2>/dev/null') ?? '';
    preg_match_all('/^fpr:+([0-9A-F]{40}):/m', $lines, $m);

    return $m[1];
}

/** Fingerprint of the (sub)key that actually made the signature. */
function gpgServiceTestSignerOf(string $home, string $signedPath): ?string
{
    $status = shell_exec('gpg --homedir '.escapeshellarg($home).' --batch --status-fd 1 --verify '.escapeshellarg($signedPath).' 2>/dev/null') ?? '';

    return preg_match('/\[GNUPG:\] VALIDSIG ([0-9A-F]{40})/', $status, $m) ? $m[1] : null;
}

beforeEach(function () {
    if (trim((string) shell_exec('command -v gpg')) === '') {
        $this->markTestSkipped('gpg binary not available');
    }

    $this->keyring = gpgServiceTestKeyring();
    $this->input = $this->keyring['home'].'/payload.json';
    $this->output = $this->keyring['home'].'/payload.out';
    file_put_contents($this->input, '{"license_id":"test"}');
});

afterEach(function () {
    if (isset($this->keyring)) {
        exec('gpgconf --homedir '.escapeshellarg($this->keyring['home']).' --kill gpg-agent 2>/dev/null');
        File::deleteDirectory($this->keyring['home']);
    }
});

test('clearsign without --local-user picks the newest signing subkey', function () {
    (new GpgService)->clearsign($this->input, $this->output, $this->keyring['home'], GPG_SERVICE_TEST_PASSPHRASE);

    expect(gpgServiceTestSignerOf($this->keyring['home'], $this->output))->toBe($this->keyring['signSub']);
});

test('clearsign pinned to the licence-signing subkey signs with that subkey', function () {
    (new GpgService)->clearsign($this->input, $this->output, $this->keyring['home'], GPG_SERVICE_TEST_PASSPHRASE, $this->keyring['signSub'].'!');

    expect(gpgServiceTestSignerOf($this->keyring['home'], $this->output))->toBe($this->keyring['signSub']);
});

test('sign pinned to the primary signs with the primary, not the newer subkey', function () {
    (new GpgService)->sign($this->input, $this->output, $this->keyring['home'], GPG_SERVICE_TEST_PASSPHRASE, $this->keyring['primary'].'!');

    expect(gpgServiceTestSignerOf($this->keyring['home'], $this->output))->toBe($this->keyring['primary']);
});

test('signing with a wrong passphrase fails', function () {
    (new GpgService)->clearsign($this->input, $this->output, $this->keyring['home'], 'wrong passphrase', $this->keyring['signSub'].'!');
})->throws(RuntimeException::class);

test('decrypt reads the passphrase from stdin', function () {
    $encrypted = $this->keyring['home'].'/payload.gpg';
    (new GpgService)->encrypt($this->input, $encrypted, $this->keyring['home'], 'gpgsvc@sos-vault.local');

    (new GpgService)->decrypt($encrypted, $this->output, $this->keyring['home'], GPG_SERVICE_TEST_PASSPHRASE);

    expect(file_get_contents($this->output))->toBe('{"license_id":"test"}');
});

test('module:build no longer accepts the passphrase as a command-line value', function () {
    $this->artisan('module:build', ['id' => 'german-support', '--passphrase' => 'x']);
})->throws(InvalidOptionException::class);

test('the passphrase is never passed on the gpg command line', function () {
    $source = file_get_contents(app_path('Services/GpgService.php'));

    expect($source)->not->toContain("'--passphrase',")
        ->and($source)->toContain("'--passphrase-fd', '0'");
});

test('module:build signs with the master primary key, reading the passphrase from a file', function () {
    $id = 'zz-gpgsvc-test-'.getmypid();
    $sourceDir = base_path("module_builder/{$id}");
    File::ensureDirectoryExists($sourceDir);
    file_put_contents("{$sourceDir}/manifest.json", json_encode(['id' => $id, 'version' => '0.0.1']));
    $passFile = $this->keyring['home'].'/pass.txt';
    file_put_contents($passFile, "s3cret pass\n");

    $gpg = Mockery::mock(GpgService::class);
    $gpg->shouldReceive('sign')
        ->once()
        ->withArgs(function ($in, $out, $home, $pass, $localUser) {
            file_put_contents($out, 'signed');

            return $pass === 's3cret pass' && $localUser === config('product.master_signing_key');
        });
    app()->instance(GpgService::class, $gpg);

    try {
        $this->artisan('module:build', ['id' => $id, '--gpg-home' => $this->keyring['home'], '--passphrase-file' => $passFile])
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($sourceDir);
        File::delete(glob(base_path("module_builder/dist/{$id}-*")) ?: []);
    }
});
