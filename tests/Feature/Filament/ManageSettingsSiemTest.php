<?php

// Load namespace-level getSvaultKey stubs so App\Services calls resolve to a
// deterministic 32-byte test key instead of the Linux kernel keyring — required
// for the encrypt-at-rest assertions below.
require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Filament\Pages\ManageSettings;
use App\Models\Sysevent;
use App\Models\User;
use App\Services\SiemSettingsService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Wave\Setting;

beforeEach(function () {
    // The SIEM section is visible on the SaaS build (isSaas()) and on a licensed
    // appliance. Pin to saas so the section renders and its fields dehydrate.
    config(['product.type' => 'saas']);

    // Saving with SIEM enabled emits an event that dispatches the forwarding
    // job; fake the queue so these settings tests make no real network calls.
    Queue::fake();

    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

/** Encrypter using the SvaultKeyStub test key (32×'T'), matching SiemSettingsService. */
function siemTestEncrypter(): Encrypter
{
    return new Encrypter(key: str_repeat('T', 32), cipher: config('app.cipher'));
}

function upsertSiem(string $key, string $plain): void
{
    Setting::updateOrCreate(
        ['key' => $key],
        ['display_name' => $key, 'value' => siemTestEncrypter()->encrypt($plain), 'type' => 'text', 'order' => 0]
    );
}

// ---------------------------------------------------------------------------
// Encryption at rest + save
// ---------------------------------------------------------------------------

it('stores every SIEM scalar encrypted at rest and round-trips through the service', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.siem.enabled', true)
        ->set('data.siem.host', 'siem.example.com')
        ->set('data.siem.port', '514')
        ->set('data.siem.protocol', 'tcp')
        ->set('data.siem.format', 'ecs')
        ->call('saveSiem');

    $storedHost = Setting::get('siem.host');

    // Stored value is ciphertext, not the plaintext host.
    expect($storedHost)->not->toBe('siem.example.com');
    expect($storedHost)->not->toBeNull();

    $service = app(SiemSettingsService::class);
    expect($service->decrypt($storedHost))->toBe('siem.example.com');
    expect($service->decrypt(Setting::get('siem.port')))->toBe('514');
    expect($service->decrypt(Setting::get('siem.enabled')))->toBe('1');
    expect($service->decrypt(Setting::get('siem.protocol')))->toBe('tcp');
    expect($service->decrypt(Setting::get('siem.format')))->toBe('ecs');
});

it('decrypts stored SIEM settings into the form on mount', function () {
    upsertSiem('siem.enabled', '1');
    upsertSiem('siem.host', '10.0.0.20');
    upsertSiem('siem.port', '6514');
    upsertSiem('siem.protocol', 'tls');
    upsertSiem('siem.format', 'rfc5424');

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->assertSet('data.siem.host', '10.0.0.20')
        ->assertSet('data.siem.port', '6514')
        ->assertSet('data.siem.protocol', 'tls')
        ->assertSet('data.siem.format', 'rfc5424');
});

it('never sends the stored certificate ciphertext to the browser', function () {
    upsertSiem('siem.protocol', 'tls');
    upsertSiem('siem.ca_cert', "-----BEGIN CERTIFICATE-----\nMIID...\n-----END CERTIFICATE-----");

    $this->actingAs($this->admin);

    // The write-only certificate is never loaded into the form: state stays
    // empty (FileUpload normalises to []), so the ciphertext never reaches the
    // browser. Only the SET/NOT-SET status is surfaced via helper text.
    Livewire::test(ManageSettings::class)
        ->assertSet('data.siem.ca_cert', fn ($value) => empty($value));
});

// ---------------------------------------------------------------------------
// Conditional TLS fields
// ---------------------------------------------------------------------------

it('shows the TLS certificate uploads only when the protocol is TLS', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(ManageSettings::class);

    $component->set('data.siem.protocol', 'tls')
        ->assertSee('CA Certificate (PEM)')
        ->assertSee('SIEM Server Certificate (PEM, optional)');

    $component->set('data.siem.protocol', 'tcp')
        ->assertDontSee('CA Certificate (PEM)')
        ->assertDontSee('SIEM Server Certificate (PEM, optional)');
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('rejects an invalid SIEM host', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.siem.host', 'not a host!!')
        ->call('saveSiem')
        ->assertHasErrors('data.siem.host');

    expect(Setting::get('siem.host'))->toBeNull();
});

it('rejects an out-of-range SIEM port', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.siem.host', 'siem.example.com')
        ->set('data.siem.port', '70000')
        ->call('saveSiem')
        ->assertHasErrors('data.siem.port');
});

// ---------------------------------------------------------------------------
// Audit events
// ---------------------------------------------------------------------------

it('emits ADD_SIEM when configured for the first time', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.siem.enabled', true)
        ->set('data.siem.host', 'siem.example.com')
        ->set('data.siem.port', '514')
        ->call('saveSiem');

    expect(Sysevent::where('type', 'ADD_SIEM')->where('owner', $this->admin->id)->exists())->toBeTrue();
});

it('emits CHG_SIEM when an existing configuration is updated', function () {
    upsertSiem('siem.host', 'old.example.com');
    upsertSiem('siem.enabled', '1');

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.siem.enabled', true)
        ->set('data.siem.host', 'new.example.com')
        ->set('data.siem.port', '514')
        ->call('saveSiem');

    expect(Sysevent::where('type', 'CHG_SIEM')->exists())->toBeTrue();
    expect(Sysevent::where('type', 'ADD_SIEM')->exists())->toBeFalse();
});

it('emits DEL_SIEM when forwarding is disabled', function () {
    upsertSiem('siem.host', 'old.example.com');
    upsertSiem('siem.enabled', '1');

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.siem.enabled', false)
        ->set('data.siem.host', 'old.example.com')
        ->set('data.siem.port', '514')
        ->call('saveSiem');

    expect(Sysevent::where('type', 'DEL_SIEM')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Build-type gating
// ---------------------------------------------------------------------------

it('renders the SIEM Integration section on the saas build', function () {
    config(['product.type' => 'saas']);
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)->assertSee('SIEM Integration');
});

it('hides the SIEM Integration section on an unlicensed appliance', function () {
    config(['product.type' => 'appliance']);
    $this->actingAs($this->admin);

    // applianceLicensed() is false with no license, so the section is hidden.
    Livewire::test(ManageSettings::class)->assertDontSee('Enable SIEM forwarding');
});
