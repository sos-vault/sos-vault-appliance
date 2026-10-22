<?php

/**
 * Sprint 5 Step D — CertificateManagerService wraps sysadmin/cert-helper.
 *
 * The PHP layer must:
 *   - shell out only via the helper (no direct cp / openssl / docker calls)
 *   - stage uploaded PEM contents to a tmp file before invoking the helper
 *     and clean those files up afterward
 *   - parse `inspect` output (subject= / issuer= / notAfter=) into a struct
 *   - surface non-zero exits as RuntimeException with stderr in the message
 *
 * All real cert / nginx operations are stubbed via Process::fake() so the
 * suite runs on hosts that don't have docker / openssl available.
 */

use App\Services\CertificateManagerService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config(['appliance.cert_helper' => '/usr/local/bin/cert-helper-test']);
});

it('stages fullchain + privkey to tmp files and invokes the helper', function () {
    Process::fake([
        '*cert-helper-test*install*' => Process::result(output: ''),
    ]);

    (new CertificateManagerService)->install('FULLCHAIN-PEM', 'PRIVKEY-PEM');

    Process::assertRan(function (PendingProcess $process) {
        $cmd = $process->command;
        $argv = is_array($cmd) ? $cmd : explode(' ', (string) $cmd);
        // expect: [helper, install, /tmp/sosvault-cert-XXXX, /tmp/sosvault-cert-YYYY]
        if (! in_array('install', $argv, true)) {
            return false;
        }
        $tmpArgs = array_filter(array_slice($argv, 2), fn ($a) => str_contains($a, 'sosvault-cert-'));

        return count($tmpArgs) === 2;
    });
});

it('cleans up tmp files even when the helper fails', function () {
    Process::fake([
        '*cert-helper-test*install*' => Process::result(
            output: '',
            errorOutput: 'unable to load certificate',
            exitCode: 1,
        ),
    ]);

    expect(fn () => (new CertificateManagerService)->install('bad-cert', 'bad-key'))
        ->toThrow(RuntimeException::class, 'unable to load certificate');

    // Tmp files must not be left behind; check the only known prefix isn't
    // populated with leftovers from this test.
    $leftovers = glob(sys_get_temp_dir().'/sosvault-cert-*') ?: [];
    expect($leftovers)->toBeEmpty();
});

it('shells out to install-corp-ca with the staged path', function () {
    Process::fake([
        '*cert-helper-test*install-corp-ca*' => Process::result(output: ''),
    ]);

    (new CertificateManagerService)->installCorpCa('-----BEGIN CERTIFICATE-----\nstub\n-----END CERTIFICATE-----');

    Process::assertRan(function (PendingProcess $process) {
        $cmd = $process->command;
        $argv = is_array($cmd) ? $cmd : explode(' ', (string) $cmd);

        return in_array('install-corp-ca', $argv, true)
            && count(array_filter($argv, fn ($a) => str_contains($a, 'sosvault-cert-'))) === 1;
    });
});

it('generateSelfSigned() invokes the helper self-signed subcommand', function () {
    Process::fake([
        '*cert-helper-test*self-signed*' => Process::result(output: ''),
    ]);

    (new CertificateManagerService)->generateSelfSigned();

    Process::assertRan(function (PendingProcess $process) {
        $cmd = $process->command;
        $argv = is_array($cmd) ? $cmd : explode(' ', (string) $cmd);

        return in_array('self-signed', $argv, true);
    });
});

it('parses inspect output into subject / issuer / expires_at', function () {
    $out = "subject=CN = sos-vault.example.com\n"
        ."issuer=CN = Internal CA\n"
        ."notAfter=Apr 30 12:00:00 2027 GMT\n";

    Process::fake([
        '*cert-helper-test*inspect*' => Process::result(output: $out),
    ]);

    $info = (new CertificateManagerService)->inspect();

    expect($info['subject'])->toBe('CN = sos-vault.example.com')
        ->and($info['issuer'])->toBe('CN = Internal CA')
        ->and($info['expires_at'])->not->toBeNull()
        ->and($info['expires_at']->year)->toBe(2027);
});

it('surfaces helper stderr on non-zero exit', function () {
    Process::fake([
        '*cert-helper-test*inspect*' => Process::result(
            output: '',
            errorOutput: 'missing: /etc/nginx/ssl/sos-vault.com/fullchain.pem',
            exitCode: 1,
        ),
    ]);

    expect(fn () => (new CertificateManagerService)->inspect())
        ->toThrow(RuntimeException::class, 'missing:');
});
