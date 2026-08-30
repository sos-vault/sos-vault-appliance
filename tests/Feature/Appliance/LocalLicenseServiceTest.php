<?php

/**
 * Sprint 5 / PHASE 6 Step B — appliance .lic install flow.
 *
 * LocalLicenseService::install() must:
 *   - reject payloads whose GPG signature does not verify
 *   - reject payloads whose machine_tokens do not contain the live host's token
 *   - persist the verified payload and surface it via LocalLicense::current()
 *
 * GpgService and MachineTokenService are mocked so the test runner does not
 * need a working gpg keyring or a particular /etc/machine-id value.
 */

use App\Models\Group;
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

function makeServiceWithMocks(array $verifyReturns, string $hostToken, string $bindingStrength = 'weak'): LocalLicenseService
{
    $gpg = Mockery::mock(GpgService::class);
    $gpg->shouldReceive('verifyClearsign')
        ->andReturnUsing(function () use (&$verifyReturns) {
            $next = array_shift($verifyReturns);
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        });

    // MachineTokenService now exposes currentHostTokens() (plural) — the
    // install gate reads both that array and the $bindingStrength property
    // so the tests have to stub both. The legacy currentHostToken() is
    // still mocked for any older call sites.
    $machine = Mockery::mock(MachineTokenService::class);
    $machine->bindingStrength = $bindingStrength;
    $machine->shouldReceive('currentHostToken')->andReturn($hostToken);
    $machine->shouldReceive('currentHostTokens')->andReturn([$hostToken]);

    return new LocalLicenseService(
        new LicenseGeneratorService($gpg),
        $machine,
    );
}

it('persists a LocalLicense row when signature and machine token both check out', function () {
    $payload = [
        'license_id' => 'aaaa1111-bbbb-2222-cccc-333344445555',
        'customer_id' => $this->user->id,
        'machine_tokens' => ['sha256:host-token-value'],
        'seats' => 5,
        'features' => ['srms', 'ai_analysis'],
        'status' => 'ACTIVE',
        'issued_at' => now()->toIso8601String(),
        'expires_at' => now()->addYear()->toIso8601String(),
    ];

    $service = makeServiceWithMocks([json_encode($payload)], 'sha256:host-token-value');

    $license = $service->install('-----BEGIN PGP SIGNED MESSAGE-----...', $this->user->id);

    expect($license)->toBeInstanceOf(LocalLicense::class);
    expect($license->uuid)->toBe('aaaa1111-bbbb-2222-cccc-333344445555');
    expect($license->seats)->toBe(5);
    expect($license->features)->toBe(['srms', 'ai_analysis']);
    expect($license->uploaded_by)->toBe($this->user->id);
    expect(LocalLicense::current()?->id)->toBe($license->id);
});

it('rejects a license whose signature does not verify', function () {
    $service = makeServiceWithMocks([new RuntimeException('bad signature')], 'sha256:host-token-value');

    expect(fn () => $service->install('tampered'))
        ->toThrow(RuntimeException::class, 'Signature verification failed');

    expect(LocalLicense::count())->toBe(0);
});

it('rejects a license bound to a different machine_token', function () {
    $payload = [
        'license_id' => 'wrong-host-uuid',
        'customer_id' => $this->user->id,
        'machine_tokens' => ['sha256:some-other-host'],
        'seats' => 3,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'issued_at' => now()->toIso8601String(),
        'expires_at' => now()->addYear()->toIso8601String(),
    ];

    $service = makeServiceWithMocks([json_encode($payload)], 'sha256:this-host');

    expect(fn () => $service->install('content'))
        ->toThrow(RuntimeException::class, 'License is not valid for this server');

    expect(LocalLicense::count())->toBe(0);
});

it('rejects a payload missing required fields', function () {
    $payload = ['license_id' => 'x', 'seats' => 1]; // missing the rest

    $service = makeServiceWithMocks([json_encode($payload)], 'sha256:any');

    expect(fn () => $service->install('content'))
        ->toThrow(RuntimeException::class, 'License payload is missing required field');
});

it('keeps history rows but current() returns the most recent active license', function () {
    $first = [
        'license_id' => '11111111-1111-1111-1111-111111111111',
        'customer_id' => $this->user->id,
        'machine_tokens' => ['sha256:host'],
        'seats' => 2,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'issued_at' => now()->subYear()->toIso8601String(),
        'expires_at' => now()->addMonth()->toIso8601String(),
    ];
    $second = [
        'license_id' => '22222222-2222-2222-2222-222222222222',
        'customer_id' => $this->user->id,
        'machine_tokens' => ['sha256:host'],
        'seats' => 10,
        'features' => ['srms', 'ai_analysis'],
        'status' => 'ACTIVE',
        'issued_at' => now()->toIso8601String(),
        'expires_at' => now()->addYear()->toIso8601String(),
    ];

    $service = makeServiceWithMocks([json_encode($first), json_encode($second)], 'sha256:host');

    $service->install('first');
    $service->install('second');

    expect(LocalLicense::count())->toBe(2);
    expect(LocalLicense::current()?->seats)->toBe(10);
});

it('reconciles the sole appliance team cap to the available member seats on install', function () {
    config(['product.type' => 'appliance']);

    // The Default Team is seeded BEFORE any license exists, so its max_members
    // froze at the no-license fallback (8). Installing an 11-seat license (a
    // "10-user" license — one seat is the always-included admin) must raise the
    // team to the 10 seats available to members, not the raw 11 and not 8.
    $group = Group::create(['name' => 'Default Team', 'max_members' => 8]);

    $payload = [
        'license_id' => 'seat-reco-1111-2222-3333-444455556666',
        'customer_id' => $this->user->id,
        'machine_tokens' => ['sha256:host'],
        'seats' => 11,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'issued_at' => now()->toIso8601String(),
        'expires_at' => now()->addYear()->toIso8601String(),
    ];

    makeServiceWithMocks([json_encode($payload)], 'sha256:host')->install('content');

    expect($group->fresh()->max_members)->toBe(10);
});

it('does not redistribute seats when multiple appliance teams exist', function () {
    config(['product.type' => 'appliance']);

    // With more than one team the operator allocates seats per-group via the
    // seat-budgeted Max Members form; auto-reconcile must leave them alone.
    $a = Group::create(['name' => 'Team A', 'max_members' => 4]);
    $b = Group::create(['name' => 'Team B', 'max_members' => 3]);

    $payload = [
        'license_id' => 'seat-multi-1111-2222-3333-444455556666',
        'customer_id' => $this->user->id,
        'machine_tokens' => ['sha256:host'],
        'seats' => 11,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'issued_at' => now()->toIso8601String(),
        'expires_at' => now()->addYear()->toIso8601String(),
    ];

    makeServiceWithMocks([json_encode($payload)], 'sha256:host')->install('content');

    expect($a->fresh()->max_members)->toBe(4);
    expect($b->fresh()->max_members)->toBe(3);
});
