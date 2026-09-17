<?php

/**
 * License hardening — structural tests for sysadmin/machine-token-helper
 * and sysadmin/sudoers.d/sos-vault-machine-token. Mirrors the existing
 * SudoersFragmentsTest pattern: assert the verbs the helper actually
 * invokes are present in the sudoers fragment, and that no blanket
 * NOPASSWD: ALL footgun has crept in.
 */

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\AssertionFailedError;

function machineTokenHelperPath(): string
{
    return base_path('sysadmin/machine-token-helper');
}

function machineTokenSudoersPath(): string
{
    return base_path('sysadmin/sudoers.d/sos-vault-machine-token');
}

function readMtFile(string $path): string
{
    expect(is_file($path))->toBeTrue();

    return (string) file_get_contents($path);
}

function mtAssertContainsAll(string $haystack, array $needles): void
{
    foreach ($needles as $needle) {
        if (! str_contains($haystack, $needle)) {
            throw new AssertionFailedError("file is missing expected verb / line: $needle");
        }
    }
    expect(true)->toBeTrue();
}

function mtVisudoBinary(): ?string
{
    foreach (['/usr/sbin/visudo', '/sbin/visudo'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

it('ships an executable machine-token-helper', function () {
    $path = machineTokenHelperPath();
    expect(is_file($path))->toBeTrue()
        ->and(is_executable($path))->toBeTrue();
});

it('helper invokes only the two dmidecode keys the sudoers fragment allows', function () {
    $body = readMtFile(machineTokenHelperPath());

    mtAssertContainsAll($body, [
        'baseboard-serial-number',
        'system-serial-number',
    ]);

    // Helper must NOT call dmidecode without -s (full dump leaks every
    // BIOS field; sudoers rule only permits the -s variants).
    expect($body)->not->toMatch('#dmidecode\s+["\']?-t\b#')
        ->and($body)->not->toMatch('#dmidecode\s+["\']?--type\b#');
});

it('helper exits 0 with UNKNOWN rather than failing when serials are placeholder', function () {
    $body = readMtFile(machineTokenHelperPath());

    // The contract LocalLicenseService relies on: a blank-DMI VM must
    // print "UNKNOWN" so the service can fall back to weak binding
    // rather than refuse a legitimate install.
    expect($body)->toContain('UNKNOWN');
});

it('sudoers fragment grants exactly the two dmidecode -s invocations', function () {
    $body = readMtFile(machineTokenSudoersPath());

    expect($body)->toContain('www-data ALL=(root) NOPASSWD: SOSV_MACHINE_TOKEN');
    mtAssertContainsAll($body, [
        '/usr/sbin/dmidecode -s baseboard-serial-number',
        '/usr/sbin/dmidecode -s system-serial-number',
    ]);
});

it('sudoers fragment does not grant blanket NOPASSWD: ALL', function () {
    $body = readMtFile(machineTokenSudoersPath());

    expect($body)->not->toMatch('/NOPASSWD:\s*ALL\b/')
        ->and($body)->not->toContain('NOPASSWD: ALL');
});

it('sudoers fragment does not allow open-ended dmidecode flags', function () {
    // -s <key> with the exact two keys is allowlisted; ANY other flag
    // (-t, -u, -q, no flag, etc.) must NOT be permitted.
    $body = readMtFile(machineTokenSudoersPath());

    expect($body)->not->toMatch('#dmidecode\s+-t\b#')
        ->and($body)->not->toMatch('#dmidecode\s+-u\b#')
        ->and($body)->not->toMatch('#dmidecode\s+\*#');
});

it('sudoers fragment parses cleanly under visudo when available', function () {
    $visudo = mtVisudoBinary();
    if ($visudo === null) {
        $this->markTestSkipped('visudo not available on this host');
    }

    $result = Process::run([$visudo, '-cf', machineTokenSudoersPath()]);

    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());
});
