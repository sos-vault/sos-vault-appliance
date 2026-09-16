<?php

/**
 * Sprint 6 Step C — sos-vault:capture-server-report.
 *
 * The command runs sosreport (Process::fake'd here so the suite stays
 * portable across hosts without sosreport installed), then encrypts the
 * resulting .tar.xz to the SaaS support pubkey via GpgService::encrypt.
 *
 * GpgService is swapped out via the container (it uses proc_open
 * directly, not the Process facade, so it can't be Process::fake'd).
 * The fake records its arguments and produces a stub encrypted file.
 */

use App\Services\GpgService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'product.type' => 'appliance',
        'license.gpg_home_verify' => '/tmp/fake-gpg-home',
        'license.support_recipient' => 'support@sos-vault.com',
    ]);

    // Make sure storage/app/private is writeable in tests.
    @mkdir(storage_path('app/private'), 0755, true);
    @unlink(storage_path('app/private/server-report.tar.xz.gpg'));
});

afterEach(function () {
    @unlink(storage_path('app/private/server-report.tar.xz.gpg'));
});

class FakeGpgService extends GpgService
{
    public static array $calls = [];

    public function encrypt(string $inputPath, string $outputPath, string $gpgHome, string $recipient): void
    {
        self::$calls[] = compact('inputPath', 'outputPath', 'gpgHome', 'recipient');
        file_put_contents($outputPath, "STUB-ENCRYPTED:{$recipient}");
    }
}

function bindFakeGpg(): void
{
    FakeGpgService::$calls = [];
    app()->bind(GpgService::class, FakeGpgService::class);
}

/**
 * Process::fake() swap that materializes a fake sosreport tarball under the
 * --tmp-dir argument before returning success — the command globs that
 * directory to find the archive, so the file MUST exist on disk.
 */
function fakeSosreportSuccess(): void
{
    Process::fake(function (PendingProcess $process) {
        $cmd = $process->command;
        if (is_array($cmd) && in_array('--tmp-dir', $cmd, true)) {
            $idx = array_search('--tmp-dir', $cmd, true);
            $tmp = $cmd[$idx + 1];
            if (! is_dir($tmp)) {
                @mkdir($tmp, 0700, true);
            }
            $archive = $tmp.'/sosreport-sos-vault-2026-04-30-abc123.tar.xz';
            file_put_contents($archive, "fake-tarball-bytes\n");

            return Process::result(output: "Your sosreport has been generated and saved in:\n  {$archive}\n");
        }

        return Process::result(output: '');
    });
}

it('refuses to run on the saas build', function () {
    config(['product.type' => 'saas']);
    bindFakeGpg();
    Process::fake();

    $exit = $this->artisan('sos-vault:capture-server-report')
        ->expectsOutputToContain('only runs on appliance')
        ->run();

    expect($exit)->toBe(1);
    Process::assertNothingRan();
});

it('runs sosreport with the expected non-interactive flags', function () {
    bindFakeGpg();
    fakeSosreportSuccess();

    $this->artisan('sos-vault:capture-server-report')->assertExitCode(0);

    Process::assertRan(function (PendingProcess $process) {
        $cmd = $process->command;

        return is_array($cmd)
            && $cmd[0] === '/usr/sbin/sosreport'
            && in_array('--batch', $cmd, true)
            && in_array('--no-report', $cmd, true)
            && in_array('--name', $cmd, true)
            && in_array('--tmp-dir', $cmd, true);
    });
});

it('encrypts the produced archive to the configured support recipient', function () {
    bindFakeGpg();
    fakeSosreportSuccess();

    $this->artisan('sos-vault:capture-server-report')->assertExitCode(0);

    expect(FakeGpgService::$calls)->toHaveCount(1);
    $call = FakeGpgService::$calls[0];
    expect($call['recipient'])->toBe('support@sos-vault.com')
        ->and($call['gpgHome'])->toBe('/tmp/fake-gpg-home')
        ->and($call['outputPath'])->toBe(storage_path('app/private/server-report.tar.xz.gpg'))
        ->and($call['inputPath'])->toEndWith('.tar.xz');
});

it('writes the encrypted output to storage/app/private/server-report.tar.xz.gpg', function () {
    bindFakeGpg();
    fakeSosreportSuccess();

    $this->artisan('sos-vault:capture-server-report')->assertExitCode(0);

    $output = storage_path('app/private/server-report.tar.xz.gpg');
    expect(is_file($output))->toBeTrue()
        ->and(file_get_contents($output))->toBe('STUB-ENCRYPTED:support@sos-vault.com');
});

it('unlinks the plaintext sosreport archive after a successful encrypt', function () {
    bindFakeGpg();

    $observedArchive = null;
    Process::fake(function (PendingProcess $process) use (&$observedArchive) {
        $cmd = $process->command;
        if (is_array($cmd) && in_array('--tmp-dir', $cmd, true)) {
            $idx = array_search('--tmp-dir', $cmd, true);
            $tmp = $cmd[$idx + 1];
            @mkdir($tmp, 0700, true);
            $observedArchive = $tmp.'/sosreport-sos-vault-test.tar.xz';
            file_put_contents($observedArchive, 'fake');
        }

        return Process::result(output: '');
    });

    $this->artisan('sos-vault:capture-server-report')->assertExitCode(0);

    expect($observedArchive)->not->toBeNull();
    expect(is_file($observedArchive))->toBeFalse();
});

it('returns non-zero and cleans up when sosreport fails', function () {
    bindFakeGpg();

    $observedTmp = null;
    Process::fake(function (PendingProcess $process) use (&$observedTmp) {
        $cmd = $process->command;
        if (is_array($cmd) && in_array('--tmp-dir', $cmd, true)) {
            $idx = array_search('--tmp-dir', $cmd, true);
            $observedTmp = $cmd[$idx + 1];
        }

        return Process::result(
            output: '',
            errorOutput: 'sosreport: cannot find any plugins',
            exitCode: 1,
        );
    });

    $exit = $this->artisan('sos-vault:capture-server-report')
        ->expectsOutputToContain('sosreport failed')
        ->run();

    expect($exit)->toBe(1)
        ->and(FakeGpgService::$calls)->toBeEmpty()
        ->and(is_file(storage_path('app/private/server-report.tar.xz.gpg')))->toBeFalse();

    if ($observedTmp !== null) {
        expect(is_dir($observedTmp))->toBeFalse();
    }
});

it('returns non-zero when sosreport reports success but produces no archive', function () {
    bindFakeGpg();

    Process::fake(function (PendingProcess $process) {
        // Note: NO archive written to --tmp-dir.
        return Process::result(output: 'pretended success but no file');
    });

    $exit = $this->artisan('sos-vault:capture-server-report')
        ->expectsOutputToContain('did not produce')
        ->run();

    expect($exit)->toBe(1);
    expect(FakeGpgService::$calls)->toBeEmpty();
});

it('still cleans up when GpgService::encrypt throws', function () {
    fakeSosreportSuccess();

    // Bind a GpgService that throws on encrypt.
    app()->bind(GpgService::class, function () {
        return new class extends GpgService
        {
            public function encrypt(string $inputPath, string $outputPath, string $gpgHome, string $recipient): void
            {
                throw new RuntimeException('gpg: missing recipient pubkey');
            }
        };
    });

    $exit = $this->artisan('sos-vault:capture-server-report')
        ->expectsOutputToContain('missing recipient')
        ->run();

    expect($exit)->toBe(1)
        ->and(is_file(storage_path('app/private/server-report.tar.xz.gpg')))->toBeFalse();
});
