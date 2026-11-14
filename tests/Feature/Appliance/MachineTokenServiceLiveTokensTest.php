<?php

/**
 * License hardening — the LIVE fallback of MachineTokenService::currentHostTokens()
 * (used only when no encrypted fingerprint is stored). It emits one independent,
 * namespaced token per identifier it can read live: the machine-id token (when
 * /etc/machine-id is readable) and the board-serial token (when the
 * machine-token-helper returns a usable serial). When DMI is unreadable (helper
 * returns UNKNOWN or exits non-zero) the service falls back to the machine-id
 * token alone and sets $bindingStrength = 'weak' — the contract the install gate
 * relies on to decide whether to require a stronger-than-machine-id match.
 *
 * Process::fake() stubs the helper; we cannot easily fake /etc/machine-id so the
 * tests pin the helper output and the resulting binding strength / token count
 * rather than the exact token hashes. The settings table carries no fingerprint
 * here, so currentHostTokens() takes the live path.
 */

use App\Services\MachineTokenService;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // Point at the real shipped helper so MachineTokenService's
    // is_executable() check passes; Process::fake() intercepts the actual
    // invocation so we don't shell out for real.
    config(['appliance.machine_token_helper' => base_path('sysadmin/machine-token-helper')]);
});

it('returns only the primary token when the helper reports UNKNOWN', function () {
    Process::fake([
        '*machine-token-helper*' => Process::result(output: "UNKNOWN\n"),
    ]);

    $service = new MachineTokenService;
    $tokens = $service->currentHostTokens();

    expect($tokens)->toHaveCount(1)
        ->and($tokens[0])->toStartWith('sha256:')
        ->and($service->bindingStrength)->toBe('weak');
});

it('returns only the primary token when the helper is not executable', function () {
    // Default config path points at a file that does not exist — no
    // Process::fake required. Service must short-circuit and not throw.
    config(['appliance.machine_token_helper' => '/no/such/path/machine-token-helper']);

    $service = new MachineTokenService;
    $tokens = $service->currentHostTokens();

    expect($tokens)->toHaveCount(1)
        ->and($service->bindingStrength)->toBe('weak');
});

it('returns only the primary token when the helper exits non-zero', function () {
    Process::fake([
        '*machine-token-helper*' => Process::result(
            output: '',
            errorOutput: 'dmidecode failed',
            exitCode: 1,
        ),
    ]);

    $service = new MachineTokenService;
    $tokens = $service->currentHostTokens();

    expect($tokens)->toHaveCount(1)
        ->and($service->bindingStrength)->toBe('weak');
});

it('upgrades to strong binding when the helper returns a usable serial', function () {
    Process::fake([
        '*machine-token-helper*' => Process::result(output: "ABCD1234\n"),
    ]);

    $service = new MachineTokenService;
    $tokens = $service->currentHostTokens();

    // machine-id token + board-serial token = two independent tokens.
    expect(count($tokens))->toBeGreaterThanOrEqual(2)
        ->and($service->bindingStrength)->toBe('strong')
        ->and($tokens[0])->toStartWith('sha256:');
});

it('dedupes identical token components', function () {
    // If by happenstance two source combinations hash to the same value
    // (extraordinarily unlikely with sha256, but possible if components
    // are missing), the result must still be a unique array.
    Process::fake([
        '*machine-token-helper*' => Process::result(output: "SERIAL\n"),
    ]);

    $service = new MachineTokenService;
    $tokens = $service->currentHostTokens();

    expect($tokens)->toBe(array_values(array_unique($tokens)));
});

it('legacy currentHostToken() returns just the primary', function () {
    $service = new MachineTokenService;

    $primary = $service->currentHostToken();

    expect($primary)->toStartWith('sha256:');
});
