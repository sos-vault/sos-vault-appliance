<?php

use App\Models\License;
use App\Models\User;
use App\Services\GpgService;
use App\Services\LicenseGeneratorService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $fakeGpgHome = sys_get_temp_dir().'/fake-gpg-home-test';
    @mkdir($fakeGpgHome, 0700, true);
    Config::set('license.gpg_home_sign', $fakeGpgHome);
    Config::set('license.gpg_home_verify', $fakeGpgHome);
});

function makeLicense(): License
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
    ]);

    return License::create([
        'customer_id' => $user->id,
        'machine_tokens' => ['sha256:abc123'],
        'seats' => 2,
        'features' => ['srms', 'ai_analysis'],
        'status' => 'ACTIVE',
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
    ]);
}

test('generate calls gpg clearsign with correct payload and stores signed license', function () {
    $gpg = Mockery::mock(GpgService::class);
    $signedContent = "-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA512\n\n{}\n-----BEGIN PGP SIGNATURE-----\nfakesig\n-----END PGP SIGNATURE-----";

    $gpg->shouldReceive('clearsign')
        ->once()
        ->withArgs(function (string $inputPath, string $outputPath, string $gpgHome, string $passphrase) {
            // Write fake signed content to the output path so generate() can read it
            file_put_contents($outputPath, "-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA512\n\n{\"license_id\":\"test\"}\n-----BEGIN PGP SIGNATURE-----\nfakesig\n-----END PGP SIGNATURE-----");

            return true;
        });

    $service = new LicenseGeneratorService($gpg);
    $license = makeLicense();

    $result = $service->generate($license);

    expect($result)->toContain('BEGIN PGP SIGNED MESSAGE');
    expect($license->fresh()->signed_license)->toContain('BEGIN PGP SIGNED MESSAGE');
});

test('generate payload contains all required license fields', function () {
    $capturedPayload = null;

    $gpg = Mockery::mock(GpgService::class);
    $gpg->shouldReceive('clearsign')
        ->once()
        ->withArgs(function (string $inputPath, string $outputPath) use (&$capturedPayload) {
            $capturedPayload = json_decode(file_get_contents($inputPath), true);
            file_put_contents($outputPath, 'signed');

            return true;
        });

    $service = new LicenseGeneratorService($gpg);
    $license = makeLicense();
    $service->generate($license);

    expect($capturedPayload)->toHaveKeys(['license_id', 'customer_id', 'machine_tokens', 'seats', 'features', 'status', 'issued_at', 'expires_at']);
    expect($capturedPayload['seats'])->toBe(2);
    expect($capturedPayload['features'])->toBe(['srms', 'ai_analysis']);
    expect($capturedPayload['machine_tokens'])->toBe(['sha256:abc123']);
});

test('verify returns payload array when gpg signature is valid', function () {
    $fakePayload = ['license_id' => 'abc', 'seats' => 1, 'features' => []];

    $gpg = Mockery::mock(GpgService::class);
    $gpg->shouldReceive('verifyClearsign')
        ->once()
        ->andReturn(json_encode($fakePayload));

    $service = new LicenseGeneratorService($gpg);

    $result = $service->verify('-----BEGIN PGP SIGNED MESSAGE-----...');

    expect($result)->toBe($fakePayload);
});

test('verify returns false when gpg throws RuntimeException', function () {
    $gpg = Mockery::mock(GpgService::class);
    $gpg->shouldReceive('verifyClearsign')
        ->once()
        ->andThrow(new RuntimeException('bad signature'));

    $service = new LicenseGeneratorService($gpg);

    $result = $service->verify('tampered license content');

    expect($result)->toBeFalse();
});

test('revoke sets status to REVOKED and clears signed license', function () {
    $gpg = Mockery::mock(GpgService::class);
    $service = new LicenseGeneratorService($gpg);

    $license = makeLicense();
    $license->signed_license = 'some-signed-content';
    $license->save();

    $service->revoke($license, 'chargeback');

    $fresh = $license->fresh();
    expect($fresh->status)->toBe('REVOKED');
    expect($fresh->revocation_reason)->toBe('chargeback');
    expect($fresh->signed_license)->toBeNull();
});

test('license model uuid is auto-generated on create', function () {
    $license = makeLicense();
    expect($license->uuid)->toBeString()->not->toBeEmpty();
});

test('license scopes filter correctly', function () {
    $user = User::factory()->create();

    $base = [
        'customer_id' => $user->id,
        'machine_tokens' => ['sha256:x'],
        'seats' => 1,
        'features' => [],
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
    ];

    License::create(array_merge($base, ['status' => 'ACTIVE']));
    License::create(array_merge($base, ['status' => 'EXPIRED']));
    License::create(array_merge($base, ['status' => 'REVOKED']));

    expect(License::active()->count())->toBe(1);
    expect(License::expired()->count())->toBe(1);
    expect(License::revoked()->count())->toBe(1);
    expect(License::forCustomer($user->id)->count())->toBe(3);
});

test('license hasMachineToken returns correct result', function () {
    $license = makeLicense(); // has 'sha256:abc123'

    expect($license->hasMachineToken('sha256:abc123'))->toBeTrue();
    expect($license->hasMachineToken('sha256:nope'))->toBeFalse();
});
