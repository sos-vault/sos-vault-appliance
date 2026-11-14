<?php

/**
 * License hardening — LocalLicenseService::install() must match more than
 * just the primary machine-id token when the live host can derive
 * stronger tokens.
 *
 *   - Strong-binding host, primary AND stronger match in payload  → install
 *   - Strong-binding host, ONLY primary matches the payload       → reject
 *   - Strong-binding host, zero overlap with payload              → reject
 *   - Weak-binding host (helper UNKNOWN), primary match           → install
 *   - Weak-binding host, no overlap                               → reject
 *
 * This is the actual defense against the "copy /etc/machine-id to another
 * box" attack: the other box's primary token matches, but its DMI board
 * serial does not, so its secondary / tertiary tokens won't overlap with
 * the payload's secondary / tertiary tokens, and the install gate rejects.
 *
 * Mocks GpgService and MachineTokenService so the test runner does not
 * need a working gpg keyring or particular /etc/machine-id contents.
 */

use App\Models\LocalLicense;
use App\Models\User;
use App\Services\GpgService;
use App\Services\LicenseGeneratorService;
use App\Services\LocalLicenseService;
use App\Services\MachineTokenService;
use Database\Seeders\RolesTableSeeder;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
    ]);
});

function makeInstallServiceWithHostTokens(array $payload, array $hostTokens, string $bindingStrength): LocalLicenseService
{
    $gpg = Mockery::mock(GpgService::class);
    $gpg->shouldReceive('verifyClearsign')->andReturn(json_encode($payload));

    $machine = Mockery::mock(MachineTokenService::class);
    $machine->bindingStrength = $bindingStrength;
    // By convention the machine-id token is first; install() uses it to spot a
    // license that matches ONLY the (copyable) machine-id on a strong host.
    $machine->machineIdToken = $hostTokens[0];
    $machine->shouldReceive('currentHostTokens')->andReturn($hostTokens);
    $machine->shouldReceive('currentHostToken')->andReturn($hostTokens[0]);

    return new LocalLicenseService(new LicenseGeneratorService($gpg), $machine);
}

function basePayload(array $machineTokens, ?int $customerId = null): array
{
    return [
        'license_id' => 'aaaa1111-bbbb-2222-cccc-333344445555',
        'customer_id' => $customerId ?? 1,
        'machine_tokens' => $machineTokens,
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'issued_at' => now()->toIso8601String(),
        'expires_at' => now()->addYear()->toIso8601String(),
    ];
}

it('installs when host has strong binding and a stronger-than-primary token matches', function () {
    $hostTokens = ['sha256:primary', 'sha256:secondary', 'sha256:tertiary'];
    // Payload's secondary lines up with the host's secondary.
    $payload = basePayload(['sha256:primary', 'sha256:secondary', 'sha256:tertiary']);

    $service = makeInstallServiceWithHostTokens($payload, $hostTokens, 'strong');

    $license = $service->install('signed', $this->user->id);

    expect($license)->toBeInstanceOf(LocalLicense::class);
    expect(LocalLicense::count())->toBe(1);
});

it('rejects when host has strong binding but only the primary matches the payload', function () {
    // This is the copy-/etc/machine-id-to-another-box scenario. The other
    // box derives a different board serial → its secondary/tertiary tokens
    // hash to something the original license never embedded.
    $hostTokens = ['sha256:primary', 'sha256:DIFFERENT-secondary', 'sha256:DIFFERENT-tertiary'];
    $payload = basePayload(['sha256:primary', 'sha256:original-secondary', 'sha256:original-tertiary']);

    $service = makeInstallServiceWithHostTokens($payload, $hostTokens, 'strong');

    expect(fn () => $service->install('signed'))
        ->toThrow(RuntimeException::class, 'License is not valid for this server');

    expect(LocalLicense::count())->toBe(0);
});

it('rejects when no token at all overlaps with the payload', function () {
    $hostTokens = ['sha256:host-primary', 'sha256:host-secondary'];
    $payload = basePayload(['sha256:other-primary', 'sha256:other-secondary']);

    $service = makeInstallServiceWithHostTokens($payload, $hostTokens, 'strong');

    expect(fn () => $service->install('signed'))
        ->toThrow(RuntimeException::class, 'None of the host\'s machine tokens match');
});

it('installs under weak binding when only the primary token is derivable and it matches', function () {
    // Blank-DMI VM: helper returned UNKNOWN, so host only has primary.
    $hostTokens = ['sha256:primary'];
    $payload = basePayload(['sha256:primary', 'sha256:original-secondary']);

    $service = makeInstallServiceWithHostTokens($payload, $hostTokens, 'weak');

    $license = $service->install('signed', $this->user->id);

    expect($license)->toBeInstanceOf(LocalLicense::class);
});

it('rejects a weak-binding host when even the primary does not match', function () {
    $hostTokens = ['sha256:host-primary'];
    $payload = basePayload(['sha256:license-primary', 'sha256:license-secondary']);

    $service = makeInstallServiceWithHostTokens($payload, $hostTokens, 'weak');

    expect(fn () => $service->install('signed'))
        ->toThrow(RuntimeException::class, 'None of the host\'s machine tokens match');
});
