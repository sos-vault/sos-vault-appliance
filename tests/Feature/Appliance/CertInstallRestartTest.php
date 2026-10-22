<?php

/**
 * Replacing the TLS cert on the appliance: the app container (where
 * CertificateManager + cert-helper run) has no way to reload the separate nginx
 * container, so cert install just WRITES the files and the operator restarts the
 * appliance to apply them. cert-helper writes the bind-mounted, app-owned ssl
 * dir directly (no sudo — that verb isn't in the container's baked sudoers), and
 * the installer chowns the ssl dir to the app uid so those writes succeed.
 */

use App\Filament\Pages\CertificateManager;
use App\Models\User;
use App\Services\CertificateManagerService;
use Database\Seeders\RolesTableSeeder;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

it('cert-helper install writes the cert directly without sudo', function () {
    $helper = file_get_contents(base_path('sysadmin/cert-helper'));

    // The fullchain/privkey are copied without a sudo prefix...
    expect($helper)->toContain('"$CP" "$fullchain" "$SSL_DIR/fullchain.pem"')
        ->and($helper)->toContain('"$CP" "$privkey" "$SSL_DIR/privkey.pem"')
        // ...and the old sudo-prefixed cp lines are gone.
        ->and($helper)->not->toContain('"$SUDO" "$CP" "$fullchain"')
        ->and($helper)->not->toContain('"$SUDO" "$CP" "$privkey"');
});

it('the installer chowns the ssl dir to the app uid so the app can replace the cert', function () {
    $installer = file_get_contents(base_path('sysadmin/installer.sh'));

    expect($installer)->toContain('chown -R "${APP_UID}:${APP_GID}" "$ssl_dir"');
});

it('the cert success copy tells the operator to restart the appliance', function () {
    $en = require base_path('lang/en/appliance.php');

    expect($en['certificate']['notif_installed_body'])->toContain('systemctl restart sos-vault')
        ->and($en['certificate']['server_section_description'])->toContain('systemctl restart sos-vault')
        // The old "nginx reloaded automatically" wording must be gone.
        ->and($en['certificate']['notif_installed_body'])->not->toContain('reloaded');
});

it('cert-helper install-corp-ca writes the CA directly without sudo or update-ca-certificates', function () {
    $helper = file_get_contents(base_path('sysadmin/cert-helper'));

    // The CA is copied into the bind-mounted, app-owned dir without sudo...
    expect($helper)->toContain('"$CP" "$ca" "$target"')
        ->and($helper)->not->toContain('"$SUDO" "$CP" "$ca"')
        // ...and update-ca-certificates is NOT invoked in-container (the
        // UPDATE_CA_CERTS command var is gone; it's deferred to restart).
        ->and($helper)->not->toContain('UPDATE_CA_CERTS')
        ->and($helper)->not->toContain('"$SUDO"');
});

it('the entrypoint refreshes the trust bundle on boot so an installed CA applies after restart', function () {
    $entrypoint = file_get_contents(base_path('sysadmin/container_start.sh'));

    expect($entrypoint)->toContain('update-ca-certificates');
});

it('the installer creates and chowns the corp CA dir before compose up', function () {
    $installer = file_get_contents(base_path('sysadmin/installer.sh'));

    expect($installer)->toContain('ensure_corp_ca_dir')
        ->and($installer)->toContain('docker-compose/ca-certificates')
        ->and($installer)->toContain('chown -R "${APP_UID}:${APP_GID}" "$ca_dir"');
});

it('the corp CA success copy tells the operator to restart the appliance', function () {
    $en = require base_path('lang/en/appliance.php');

    expect($en['certificate']['notif_ca_installed_body'])->toContain('systemctl restart sos-vault')
        ->and($en['certificate']['ca_section_description'])->toContain('systemctl restart sos-vault');
});

it('writes the corp CA and notifies the operator to restart', function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);

    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $this->mock(CertificateManagerService::class, function ($mock) {
        $mock->shouldReceive('installCorpCa')->once();
        $mock->shouldReceive('inspect')->andReturn(['subject' => '', 'issuer' => '', 'expires_at' => null]);
    });

    Livewire::test(CertificateManager::class)
        ->set('data.corp_ca', UploadedFile::fake()->createWithContent('corp-ca.pem', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n"))
        ->call('installCorpCa');

    Notification::assertNotified(__('appliance.certificate.notif_ca_installed_title'));
});

it('cert-helper has a self-signed verb that regenerates the pair in place', function () {
    $helper = file_get_contents(base_path('sysadmin/cert-helper'));

    expect($helper)->toContain('self-signed)')
        ->and($helper)->toContain("-subj '/CN=sos-vault.local'");
});

it('regenerates a self-signed cert from the admin page and notifies the operator to restart', function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);

    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $this->mock(CertificateManagerService::class, function ($mock) {
        $mock->shouldReceive('generateSelfSigned')->once();
        $mock->shouldReceive('inspect')->andReturn(['subject' => '', 'issuer' => '', 'expires_at' => null]);
    });

    Livewire::test(CertificateManager::class)
        ->call('regenerateSelfSigned');

    Notification::assertNotified(__('appliance.certificate.notif_self_signed_title'));
});

it('ships a host-side reset-tls-cert.sh recovery script that regenerates + restarts', function () {
    $script = base_path('sysadmin/reset-tls-cert.sh');

    expect(is_file($script))->toBeTrue()
        ->and(is_executable($script))->toBeTrue();

    // Dry-run (no root needed) must plan a self-signed regen + a restart, exit 0.
    $result = Process::run(['bash', $script, '--dry-run', '--yes']);

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toContain('openssl req -x509')
        ->and($result->output())->toContain('CN=sos-vault.local');
});

it('writes the cert without reloading nginx and notifies the operator to restart', function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);

    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    // The service must install the cert but NEVER reload (no docker reach).
    $this->mock(CertificateManagerService::class, function ($mock) {
        $mock->shouldReceive('install')->once();
        $mock->shouldReceive('reload')->never();
        $mock->shouldReceive('inspect')->andReturn(['subject' => '', 'issuer' => '', 'expires_at' => null]);
    });

    Livewire::test(CertificateManager::class)
        ->set('data.fullchain', UploadedFile::fake()->createWithContent('fullchain.pem', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n"))
        ->set('data.privkey', UploadedFile::fake()->createWithContent('privkey.pem', "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----\n"))
        ->call('installCertificate');

    Notification::assertNotified(__('appliance.certificate.notif_installed_title'));
});
