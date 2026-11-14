<?php

// Load namespace-level getSvaultKey stubs so App\Services calls resolve to a
// deterministic 32-byte test key instead of the Linux kernel keyring. This is
// required for the Licensing Key and encrypted-settings (AI / ServiceNow /
// AWS secrets) tests below; other tests don't touch svault0 and are unaffected.
require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Filament\Pages\ManageSettings;
use App\Models\User;
use App\Services\LicensingPassphraseService;
use App\Services\SettingsEncryptionService;
use Database\Seeders\RolesTableSeeder;
use Filament\Notifications\Notification;
use Illuminate\Encryption\Encrypter;
use Livewire\Livewire;
use Wave\Setting;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

/** Upsert a setting row with all required non-null columns. */
function upsertSetting(string $key, string $value): void
{
    Setting::updateOrCreate(
        ['key' => $key],
        ['display_name' => $key, 'value' => $value, 'type' => 'text', 'order' => 0]
    );
}

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('redirects guests away from the settings page', function () {
    $this->get('/admin/manage-settings')->assertRedirect();
});

it('denies access to non-admin users', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/admin/manage-settings')->assertForbidden();
});

// ---------------------------------------------------------------------------
// Mount / form population
// ---------------------------------------------------------------------------

it('loads existing settings into the form data on mount', function () {
    upsertSetting('site.title', 'My SOS Vault');
    upsertSetting('ai.openai_api_key', 'sk-loaded');
    upsertSetting('logging.channel', 'daily');

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->assertSet('data.site.title', 'My SOS Vault')
        ->assertSet('data.ai.openai_api_key', 'sk-loaded')
        ->assertSet('data.logging.channel', 'daily');
});

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------

it('saves updated settings to the database', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(ManageSettings::class)
        ->set('data.site.title', 'Updated Title')
        ->call('saveGroup', 'site', 'Site');

    expect(Setting::get('site.title'))->toBe('Updated Title');

    Livewire::test(ManageSettings::class)
        // The OpenAI key field only shows (and dehydrates) when OpenAI is selected.
        ->set('data.ai.provider', 'openai')
        ->set('data.ai.openai_api_key', 'sk-new-key')
        ->call('saveGroup', 'ai', 'AI');

    $stored = Setting::get('ai.openai_api_key');

    // Stored at rest as ciphertext, not the plaintext key.
    expect($stored)->not->toBe('sk-new-key');
    expect(app(SettingsEncryptionService::class)->decrypt($stored))->toBe('sk-new-key');
});

// ---------------------------------------------------------------------------
// Encrypted-at-rest settings: AI provider keys, ServiceNow password, AWS secret
// ---------------------------------------------------------------------------

it('encrypts AI provider API keys at rest and decrypts them back into the form', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.ai.provider', 'anthropic')
        ->set('data.ai.anthropic_api_key', 'sk-ant-secret')
        ->call('saveGroup', 'ai', 'AI Assistant');

    $stored = Setting::get('ai.anthropic_api_key');
    expect($stored)->not->toBe('sk-ant-secret');
    expect(app(SettingsEncryptionService::class)->decrypt($stored))->toBe('sk-ant-secret');

    // mount() decrypts it back for display, same as every other password field.
    Livewire::test(ManageSettings::class)
        ->assertSet('data.ai.anthropic_api_key', 'sk-ant-secret');
});

it('encrypts the ServiceNow (ITSM) password at rest', function () {
    config(['product.type' => 'saas']);
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.servicenow.instance', 'https://dev12345.service-now.com')
        ->set('data.servicenow.username', 'svc-account')
        ->set('data.servicenow.password', 'itsm-secret')
        ->call('saveGroup', 'servicenow', 'ServiceNow');

    // Non-secret fields stay plaintext.
    expect(Setting::get('servicenow.instance'))->toBe('https://dev12345.service-now.com');
    expect(Setting::get('servicenow.username'))->toBe('svc-account');

    $stored = Setting::get('servicenow.password');
    expect($stored)->not->toBe('itsm-secret');
    expect(app(SettingsEncryptionService::class)->decrypt($stored))->toBe('itsm-secret');
});

it('encrypts the AWS secret access key at rest but leaves the access key id plaintext', function () {
    config(['product.type' => 'saas']);
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.aws.access_key_id', 'AKIAIOSFODNN7EXAMPLE')
        ->set('data.aws.secret_access_key', 'aws-secret-value')
        ->call('saveGroup', 'aws', 'AWS / S3');

    expect(Setting::get('aws.access_key_id'))->toBe('AKIAIOSFODNN7EXAMPLE');

    $stored = Setting::get('aws.secret_access_key');
    expect($stored)->not->toBe('aws-secret-value');
    expect(app(SettingsEncryptionService::class)->decrypt($stored))->toBe('aws-secret-value');
});

it('treats a pre-existing plaintext secret as legacy data and still loads it on mount', function () {
    // Simulates an install upgrading from before these fields were encrypted.
    upsertSetting('ai.openai_api_key', 'legacy-plaintext-key');

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->assertSet('data.ai.openai_api_key', 'legacy-plaintext-key');
});

it('shows only the selected provider\'s fields in the AI Assistant section', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(ManageSettings::class);

    // OpenAI selected: OpenAI fields show; local/ollama/anthropic fields are hidden.
    $component->set('data.ai.provider', 'openai')
        ->assertSee('OpenAI Model')
        ->assertDontSee('Local LLM URL')
        ->assertDontSee('Ollama Server URL')
        ->assertDontSee('Anthropic Model')
        // Shared tunables apply to every provider, so they stay visible.
        ->assertSee('Max Response Tokens')
        ->assertSee('Enable Current-Sosreport Analysis');

    // Local (default) selected: only the local fields show; cloud/ollama hidden.
    $component->set('data.ai.provider', 'local')
        ->assertSee('Local LLM URL')
        ->assertDontSee('OpenAI Model')
        ->assertDontSee('Anthropic Model')
        ->assertDontSee('Ollama Server URL')
        // Analysis is auto-forced off on the tiny local model, so its toggle hides.
        ->assertDontSee('Enable Current-Sosreport Analysis');

    // Ollama selected: Ollama fields + the tool-calling toggle show.
    $component->set('data.ai.provider', 'ollama')
        ->assertSee('Ollama Server URL')
        ->assertSee('Ollama Supports Tool-Calling (agentic analysis)')
        ->assertDontSee('Local LLM URL')
        ->assertDontSee('OpenAI Model');
});

it('saves the Ollama tool-calling toggle when on-prem Ollama is the selected provider', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.ai.provider', 'ollama')
        ->set('data.ai.ollama_tools', true)
        ->call('saveGroup', 'ai', 'AI Assistant');

    expect(Setting::get('ai.ollama_tools'))->toBe('1');
});

it('preserves the Ollama tool-calling setting when saving under a non-Ollama provider (toggle disabled)', function () {
    upsertSetting('ai.ollama_tools', '1');

    $this->actingAs($this->admin);

    // Provider is OpenAI, so the toggle is disabled and not dehydrated: saving the
    // AI group must not clear the previously stored Ollama value.
    Livewire::test(ManageSettings::class)
        ->set('data.ai.provider', 'openai')
        ->set('data.ai.openai_model', 'gpt-4o')
        ->call('saveGroup', 'ai', 'AI Assistant');

    expect(Setting::get('ai.ollama_tools'))->toBe('1');
});

it('does not persist the read-only AI provider-profile summary as a setting', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.ai.local_model', 'qwen2.5-1.5b-instruct')
        ->call('saveGroup', 'ai', 'AI Assistant');

    expect(Setting::get('ai.local_model'))->toBe('qwen2.5-1.5b-instruct')
        ->and(Setting::where('key', 'ai.profile_summary')->exists())->toBeFalse();
});

it('shows a success notification after saving', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.site.title', 'Notify Test')
        ->call('saveGroup', 'site', 'Site');

    Notification::assertNotified('Site settings saved');
});

// ---------------------------------------------------------------------------
// Logging section
// ---------------------------------------------------------------------------

it('saves logging channel and level settings', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.logging.channel', 'single')
        ->set('data.logging.level', 'error')
        ->set('data.logging.deprecations_channel', 'null')
        ->call('saveGroup', 'logging', 'Logging');

    expect(Setting::get('logging.channel'))->toBe('single');
    expect(Setting::get('logging.level'))->toBe('error');
    expect(Setting::get('logging.deprecations_channel'))->toBe('null');
});

// ---------------------------------------------------------------------------
// Mail section
// ---------------------------------------------------------------------------

it('saves mail settings to the database', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.mail.mailer', 'smtp')
        ->set('data.mail.host', 'smtp.sendgrid.net')
        ->set('data.mail.port', '465')
        ->set('data.mail.encryption', 'ssl')
        ->set('data.mail.username', 'apikey')
        ->set('data.mail.password', 'sg-secret')
        ->set('data.mail.from_address', 'noreply@sos-vault.com')
        ->set('data.mail.from_name', 'SOS Vault')
        ->call('saveGroup', 'mail', 'Mail');

    expect(Setting::get('mail.mailer'))->toBe('smtp');
    expect(Setting::get('mail.host'))->toBe('smtp.sendgrid.net');
    expect(Setting::get('mail.port'))->toBe('465');
    expect(Setting::get('mail.encryption'))->toBe('ssl');
    expect(Setting::get('mail.username'))->toBe('apikey');
    expect(Setting::get('mail.from_address'))->toBe('noreply@sos-vault.com');
    expect(Setting::get('mail.from_name'))->toBe('SOS Vault');
});

it('loads mail settings into form on mount', function () {
    upsertSetting('mail.host', 'smtp.example.com');
    upsertSetting('mail.from_address', 'hello@example.com');
    upsertSetting('mail.from_name', 'My App');

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->assertSet('data.mail.host', 'smtp.example.com')
        ->assertSet('data.mail.from_address', 'hello@example.com')
        ->assertSet('data.mail.from_name', 'My App');
});

// ---------------------------------------------------------------------------
// Mail section — Send Test Email action
// ---------------------------------------------------------------------------

it('sends a test email and shows a success notification', function () {
    config(['mail.default' => 'array']);

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->call('sendTestEmail', 'recipient@example.com', 'Test Subject', '<p>Hello world</p>');

    Notification::assertNotified('Test email queued');
});

it('always queues successfully even with a broken mail config', function () {
    // SendEmailListener implements ShouldQueue, so the dispatch always succeeds
    // regardless of SMTP config; failures only surface in the queue worker logs.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'invalid.host.invalid', 'mail.mailers.smtp.port' => 1]);

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->call('sendTestEmail', 'recipient@example.com', 'Test Subject', '<p>Hello world</p>');

    Notification::assertNotified('Test email queued');
});

// ---------------------------------------------------------------------------
// Config hardcoding
// ---------------------------------------------------------------------------

it('uses sqlite as the hardcoded default database connection', function () {
    expect(config('database.default'))->toBe('sqlite');
});

it('uses stack as the hardcoded default log channel', function () {
    expect(config('logging.default'))->toBe('stack');
});

it('uses null as the hardcoded deprecations log channel', function () {
    expect(config('logging.deprecations.channel'))->toBe('null');
});

// ---------------------------------------------------------------------------
// Licensing Key section
// ---------------------------------------------------------------------------

/** Encrypter using the SvaultKeyStub test key (32×'T'). */
function licensingTestEncrypter(): Encrypter
{
    return new Encrypter(key: str_repeat('T', 32), cipher: config('app.cipher'));
}

it('never populates the licensing passphrase field on mount', function () {
    // Pre-seed an encrypted value as if a passphrase were already stored.
    $cipher = licensingTestEncrypter()->encrypt('s3cret-passphrase');
    upsertSetting(LICENSING_PASSPHRASE_SETTING_KEY, $cipher);

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->assertSet('data.licensing.master_gpg_passphrase', null);
});

it('preserves the existing licensing passphrase when an empty value is submitted', function () {
    $original = licensingTestEncrypter()->encrypt('original-secret');
    upsertSetting(LICENSING_PASSPHRASE_SETTING_KEY, $original);

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.licensing.master_gpg_passphrase', '')
        ->call('saveLicensing');

    Notification::assertNotified('No change to Licensing Key');

    expect(Setting::get(LICENSING_PASSPHRASE_SETTING_KEY))->toBe($original);
});

it('encrypts and stores a non-empty licensing passphrase, then clears the field', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(ManageSettings::class)
        ->set('data.licensing.master_gpg_passphrase', 'brand-new-passphrase')
        ->call('saveLicensing');

    Notification::assertNotified('Licensing Key saved');

    $stored = Setting::get(LICENSING_PASSPHRASE_SETTING_KEY);

    // Stored value must NOT be plaintext.
    expect($stored)->not->toBe('brand-new-passphrase');
    expect($stored)->not->toBeNull();

    // Stored ciphertext round-trips through the service.
    $service = app(LicensingPassphraseService::class);
    expect($service->decrypt($stored))->toBe('brand-new-passphrase');

    // Form field is scrubbed so the plaintext does not echo back.
    $component->assertSet('data.licensing.master_gpg_passphrase', '');
});

it('overwrites an existing licensing passphrase when a new value is submitted', function () {
    $original = licensingTestEncrypter()->encrypt('first-secret');
    upsertSetting(LICENSING_PASSPHRASE_SETTING_KEY, $original);

    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->set('data.licensing.master_gpg_passphrase', 'replacement-secret')
        ->call('saveLicensing');

    $stored = Setting::get(LICENSING_PASSPHRASE_SETTING_KEY);
    expect($stored)->not->toBe($original);
    expect(app(LicensingPassphraseService::class)->decrypt($stored))->toBe('replacement-secret');
});

it('exposes the licensing passphrase via getMasterGpgPassphrase()', function () {
    $cipher = licensingTestEncrypter()->encrypt('helper-roundtrip');
    upsertSetting(LICENSING_PASSPHRASE_SETTING_KEY, $cipher);

    expect(getMasterGpgPassphrase())->toBe('helper-roundtrip');
    expect(hasMasterGpgPassphrase())->toBeTrue();
});

it('returns empty string from getMasterGpgPassphrase() when no value is stored', function () {
    expect(getMasterGpgPassphrase())->toBe('');
    expect(hasMasterGpgPassphrase())->toBeFalse();
});

it('renders the Licensing Key section under the saas build', function () {
    config(['product.type' => 'saas']);
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->assertSee('Licensing Key');
});

it('hides the Licensing Key section on the appliance build', function () {
    config(['product.type' => 'appliance']);
    $this->actingAs($this->admin);

    Livewire::test(ManageSettings::class)
        ->assertDontSee('Licensing Key')
        ->assertDontSee('Master GPG Passphrase');
});
