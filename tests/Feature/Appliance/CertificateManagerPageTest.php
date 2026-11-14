<?php

/**
 * Sprint 5 Step D — CertificateManager Filament page acceptance probe.
 *
 * Mirrors DiskManagerPageTest: the page must be reachable under appliance
 * and gated under SaaS. The render path pulls cert data from
 * CertificateManagerService::inspect(), which we fake so tests don't need
 * openssl + a live cert on the host.
 */

use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    // Same dance as DiskManagerPageTest: seed license + admin under SaaS
    // (the global Pest default) so the appliance seat-guard doesn't fire,
    // then flip product.type per-test.
    LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => "-----BEGIN PGP SIGNED MESSAGE-----\nstub\n-----END PGP SIGNED MESSAGE-----",
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);

    $this->admin = User::factory()->create([
        'email_verified_at' => now(),
        'verified' => 1,
    ]);
    $this->admin->syncRoles(['admin']);
});

it('renders the CertificateManager page under the appliance build', function () {
    config(['product.type' => 'appliance']);
    config(['appliance.cert_helper' => '/usr/local/bin/cert-helper-test']);

    Process::fake([
        '*cert-helper-test*inspect*' => Process::result(output: "subject=CN = sos-vault.example\n".
            "issuer=CN = Internal CA\n".
            "notAfter=Apr 30 12:00:00 2027 GMT\n"
        ),
    ]);

    actingAs($this->admin);

    get('/admin/certificate-manager')
        ->assertSuccessful()
        ->assertSee('Current Server Certificate')
        ->assertSee('Replace Server Certificate')
        ->assertSee('Corporate Root CA')
        ->assertSee('sos-vault.example');
});

it('returns 403 on /admin/certificate-manager under the saas build', function () {
    config(['product.type' => 'saas']);

    actingAs($this->admin);

    get('/admin/certificate-manager')->assertForbidden();
});

it('shows a friendly error in the cert card when the helper is unavailable', function () {
    config(['product.type' => 'appliance']);
    config(['appliance.cert_helper' => '/usr/local/bin/cert-helper-test']);

    Process::fake([
        '*cert-helper-test*' => Process::result(
            output: '',
            errorOutput: 'missing: /etc/nginx/ssl/sos-vault.com/fullchain.pem',
            exitCode: 1,
        ),
    ]);

    actingAs($this->admin);

    get('/admin/certificate-manager')
        ->assertSuccessful()
        ->assertSee('Certificate unavailable');
});
