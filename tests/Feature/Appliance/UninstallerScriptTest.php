<?php

/**
 * sysadmin/uninstaller.sh — reverses installer.sh to a clean, re-installable
 * state. Like the installer, most steps need real hardware/root to exercise
 * (systemctl, docker compose down, userdel), so these tests cover what is
 * verifiable without root:
 *   - bash -n syntax check
 *   - --help banner contents
 *   - --dry-run walks every reverse-step end-to-end without mutating state
 *   - destructive verbs are gated behind --dry-run
 *   - the data-bearing paths are opt-in (preserved by --keep-data)
 */

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\AssertionFailedError;

function uninstallerPath(): string
{
    return base_path('sysadmin/uninstaller.sh');
}

function runUninstaller(string $args = ''): ProcessResult
{
    return Process::env(['SOS_VAULT_DIR' => base_path()])
        ->run('bash '.escapeshellarg(uninstallerPath()).' '.$args);
}

it('ships an executable uninstaller.sh under sysadmin/', function () {
    expect(is_file(uninstallerPath()))->toBeTrue()
        ->and(is_executable(uninstallerPath()))->toBeTrue();
});

it('passes bash -n syntax check', function () {
    $result = Process::run('bash -n '.escapeshellarg(uninstallerPath()));

    expect($result->successful())->toBeTrue($result->errorOutput());
});

it('prints a usage banner with --help', function () {
    $result = Process::run('bash '.escapeshellarg(uninstallerPath()).' --help');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('sos-vault appliance uninstaller')
        ->and($result->output())->toContain('--dry-run')
        ->and($result->output())->toContain('--keep-data');
});

it('rejects unknown arguments', function () {
    $result = Process::run('bash '.escapeshellarg(uninstallerPath()).' --bogus');

    expect($result->successful())->toBeFalse()
        ->and($result->errorOutput())->toContain('unknown argument');
});

it('walks all 12 reverse-steps in --dry-run without mutating state', function () {
    $result = runUninstaller('--dry-run --remove-images --purge');

    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

    foreach (range(1, 12) as $n) {
        expect($result->output())->toContain(sprintf('Step %d/12', $n));
    }

    expect($result->output())->toContain('uninstall complete');
});

it('does not invoke any destructive command for real in dry-run', function () {
    $result = runUninstaller('--dry-run --remove-images --purge');

    foreach ([
        'docker compose -f',
        'systemctl stop',
        'rm -rf',
        'userdel',
        'ufw --force delete',
    ] as $verb) {
        foreach (explode("\n", $result->output()) as $line) {
            if (str_contains($line, $verb)) {
                if (! str_contains($line, '[dry-run]')) {
                    throw new AssertionFailedError(
                        "destructive verb '$verb' was not gated by --dry-run: $line"
                    );
                }
                break;
            }
        }
    }
    expect(true)->toBeTrue();
});

it('reverses the installer: stack, units, sudoers, keyring, user', function () {
    $result = runUninstaller('--dry-run');

    expect($result->output())
        ->toContain('docker compose -f')          // step 8 (compose up) reversed
        ->toContain('systemctl stop sos-vault.service') // step 11 reversed
        ->toContain('/etc/sudoers.d/sos-vault-cert')    // step 13/6 fragments
        ->toContain('/etc/default/sos-vault')           // step 11 default file
        ->toContain('svault')                            // step 6 keyring/device
        ->toContain('userdel -r sosvault');              // step 2b app user
});

it('preserves /vault and the database under --keep-data', function () {
    $kept = runUninstaller('--dry-run --keep-data');
    expect($kept->output())
        ->toContain('keeping the vault directory')
        ->toContain('keeping the application database')
        ->not->toContain('rm -rf /vault');

    // Without the flag, both are torn down.
    $full = runUninstaller('--dry-run');
    expect($full->output())
        ->toContain('rm -rf /vault')
        ->toContain('resetting the application database');
});

it('removes the sos-vault package by default; --keep-package leaves it', function () {
    // It is an *uninstaller* — the default removes the package itself.
    $result = runUninstaller('--dry-run');
    expect($result->output())
        ->toContain('Package — removing the sos-vault package')
        ->toContain('keeping docker images'); // images still kept by default

    // --keep-package preserves the deb so installer.sh can be re-run.
    $kept = runUninstaller('--dry-run --keep-package');
    expect($kept->output())
        ->toContain('keeping the sos-vault package installed')
        ->not->toContain('Package — removing the sos-vault package');
});

it('purge removes the package via the package manager, not just rm -rf', function () {
    // On a dpkg host the dry-run resolves to `apt-get purge sos-vault`; the
    // body must offer the package-manager path (and the rpm equivalent) so
    // dpkg/rpm stops tracking the package instead of leaving a stale record.
    $body = file_get_contents(uninstallerPath());

    expect($body)
        ->toContain('apt-get purge -y sos-vault')
        ->toContain('dnf remove -y sos-vault')
        ->toContain('dpkg -s sos-vault');
});

it('accepts the deprecated --purge flag as a no-op (now the default)', function () {
    $result = runUninstaller('--dry-run --purge');
    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());
    expect($result->output())->toContain('Package — removing the sos-vault package');
});

it('passes shellcheck if available', function () {
    foreach (['/usr/bin/shellcheck', '/usr/local/bin/shellcheck'] as $bin) {
        if (is_executable($bin)) {
            $result = Process::run([$bin, '--severity=error', uninstallerPath()]);
            expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

            return;
        }
    }
    $this->markTestSkipped('shellcheck not available on this host');
});
