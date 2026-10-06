<?php

/**
 * LicenseRequestService::generate() — open-core "Generate License Request"
 * flow. No longer runs an sosreport: it derives the live host's machine
 * tokens (MachineTokenService::currentHostTokens) and encodes them into a
 * single copy/paste key the operator pastes at sos-vault.com.
 *
 * Process::fake() stubs the machine-token-helper so currentHostTokens() does
 * not shell out; /etc/machine-id is read for real on the test host.
 */

use App\Services\LicenseRequestService;
use App\Services\MachineTokenService;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'product.type' => 'appliance',
        'appliance.machine_token_helper' => base_path('sysadmin/machine-token-helper'),
    ]);
});

it('refuses to run on the saas build', function () {
    config(['product.type' => 'saas']);

    expect(fn () => app(LicenseRequestService::class)->generate())
        ->toThrow(RuntimeException::class, 'only runs on appliance');
});

it('returns a SOSV1 key that decodes to the live host tokens', function () {
    Process::fake([
        '*machine-token-helper*' => Process::result(output: "UNKNOWN\n"),
    ]);

    $key = app(LicenseRequestService::class)->generate();

    expect($key)->toStartWith('SOSV1.');

    $service = new MachineTokenService;
    $expected = $service->currentHostTokens();

    expect($service->decode($key))->toBe($expected);
});

it('encodes every token the host can derive (strong binding)', function () {
    Process::fake([
        '*machine-token-helper*' => Process::result(output: "ABCD1234\n"),
    ]);

    $key = app(LicenseRequestService::class)->generate();
    $tokens = (new MachineTokenService)->decode($key);

    // Primary (machine-id) + at least the secondary (machine-id + board).
    expect(count($tokens))->toBeGreaterThanOrEqual(2)
        ->and($tokens[0])->toStartWith('sha256:');
});
