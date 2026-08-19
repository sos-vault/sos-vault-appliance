<?php

// Load namespace-level getSvaultKey stubs so App\Services calls resolve to a
// deterministic 32-byte test key instead of the Linux kernel keyring. Needed
// for the encrypted-at-rest AI/ServiceNow/AWS secret tests below.
require_once __DIR__.'/../Support/SvaultKeyStub.php';

use App\Providers\BillingSettingsServiceProvider;
use App\Services\SettingsEncryptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Wave\Setting;

beforeEach(function () {
    // Flush Wave's settings cache so each test reads fresh DB values.
    Cache::forget('wave_settings');

    // Start each test with a clean config slate for the keys we care about.
    Config::set('mail.default', null);
    Config::set('mail.mailers.smtp.host', null);
    Config::set('mail.mailers.smtp.port', null);
    Config::set('mail.mailers.smtp.encryption', null);
    Config::set('mail.mailers.smtp.username', null);
    Config::set('mail.mailers.smtp.password', null);
    Config::set('mail.from.address', null);
    Config::set('mail.from.name', null);
    Config::set('prism.providers.openai.api_key', null);
    Config::set('services.telegram.api_key', null);
    Config::set('services.telegram.chat_id', null);
    Config::set('services.recaptcha.site_key', null);
    Config::set('services.maxmind.license_key', null);
    Config::set('jwt.secret', null);
    Config::set('services.servicenow.instance', null);
    Config::set('services.servicenow.password', null);
    Config::set('filesystems.disks.s3.key', null);
    Config::set('filesystems.disks.s3.region', null);
    Config::set('filesystems.disks.s3.secret', null);
    Config::set('logging.default', null);
    Config::set('logging.channels.single.level', null);
    Config::set('logging.channels.daily.level', null);
    Config::set('logging.deprecations.channel', null);
    Config::set('wave.billing_provider', null);
});

/** Re-boots the provider so Config::set() calls are applied. */
function bootProvider(): void
{
    Cache::forget('wave_settings');
    (new BillingSettingsServiceProvider(app()))->boot();
}

/** Upsert a setting with the required non-null columns. */
function setSetting(string $key, string $value): void
{
    Setting::updateOrCreate(
        ['key' => $key],
        ['display_name' => $key, 'value' => $value, 'type' => 'text', 'order' => 0]
    );
}

// ---------------------------------------------------------------------------
// Services map
// ---------------------------------------------------------------------------

it('applies openai api key from settings to config', function () {
    // Stored encrypted at rest, same as ManageSettings::saveGroup() writes it.
    $cipher = app(SettingsEncryptionService::class)->encrypt('sk-test-openai');
    setSetting('ai.openai_api_key', $cipher);

    bootProvider();

    // Mil uses Prism, so the OpenAI key maps to the Prism provider config.
    expect(config('prism.providers.openai.api_key'))->toBe('sk-test-openai');
});

it('decrypts the servicenow password from settings into config', function () {
    $cipher = app(SettingsEncryptionService::class)->encrypt('itsm-secret');
    setSetting('servicenow.password', $cipher);

    bootProvider();

    expect(config('services.servicenow.password'))->toBe('itsm-secret');
});

it('decrypts the aws secret access key from settings into config', function () {
    $cipher = app(SettingsEncryptionService::class)->encrypt('aws-secret-value');
    setSetting('aws.secret_access_key', $cipher);

    bootProvider();

    expect(config('filesystems.disks.s3.secret'))->toBe('aws-secret-value');
});

it('falls back to a legacy plaintext ai api key when it predates encryption at rest', function () {
    setSetting('ai.openai_api_key', 'legacy-plaintext-key');

    bootProvider();

    expect(config('prism.providers.openai.api_key'))->toBe('legacy-plaintext-key');
});

it('applies telegram credentials from settings to config', function () {
    setSetting('telegram.api_key', 'tg-key-123');
    setSetting('telegram.chat_id', '-100987654');

    bootProvider();

    expect(config('services.telegram.api_key'))->toBe('tg-key-123');
    expect(config('services.telegram.chat_id'))->toBe('-100987654');
});

it('applies recaptcha site key from settings to config', function () {
    setSetting('security.recaptcha_site_key', 'rc-site-key');

    bootProvider();

    expect(config('services.recaptcha.site_key'))->toBe('rc-site-key');
});

it('applies maxmind license key from settings to config', function () {
    setSetting('security.maxmind_license_key', 'mm-license');

    bootProvider();

    expect(config('services.maxmind.license_key'))->toBe('mm-license');
});

it('applies jwt secret from settings to config', function () {
    setSetting('security.jwt_secret', 'super-secret-jwt');

    bootProvider();

    expect(config('jwt.secret'))->toBe('super-secret-jwt');
});

it('applies servicenow instance from settings to config', function () {
    setSetting('servicenow.instance', 'https://dev12345.service-now.com');

    bootProvider();

    expect(config('services.servicenow.instance'))->toBe('https://dev12345.service-now.com');
});

// ---------------------------------------------------------------------------
// AWS / S3 map
// ---------------------------------------------------------------------------

it('applies aws access key id from settings to filesystems config', function () {
    setSetting('aws.access_key_id', 'AKIAIOSFODNN7EXAMPLE');

    bootProvider();

    expect(config('filesystems.disks.s3.key'))->toBe('AKIAIOSFODNN7EXAMPLE');
});

it('applies aws default region from settings to filesystems config', function () {
    setSetting('aws.default_region', 'eu-west-1');

    bootProvider();

    expect(config('filesystems.disks.s3.region'))->toBe('eu-west-1');
});

// ---------------------------------------------------------------------------
// Billing map
// ---------------------------------------------------------------------------

it('applies billing provider from settings to wave config', function () {
    setSetting('billing.provider', 'stripe');

    bootProvider();

    expect(config('wave.billing_provider'))->toBe('stripe');
});

// ---------------------------------------------------------------------------
// Logging map
// ---------------------------------------------------------------------------

it('applies log channel from settings to logging config', function () {
    setSetting('logging.channel', 'daily');

    bootProvider();

    expect(config('logging.default'))->toBe('daily');
});

it('applies log deprecations channel from settings to logging config', function () {
    setSetting('logging.deprecations_channel', 'single');

    bootProvider();

    expect(config('logging.deprecations.channel'))->toBe('single');
});

it('applies log level to all standard channels', function () {
    setSetting('logging.level', 'warning');

    bootProvider();

    foreach (['single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'] as $channel) {
        expect(config("logging.channels.{$channel}.level"))
            ->toBe('warning', "Expected channel [{$channel}] to have level [warning]");
    }
});

// ---------------------------------------------------------------------------
// Empty / null values must not override existing config
// ---------------------------------------------------------------------------

it('does not override config when setting value is empty', function () {
    Config::set('prism.providers.openai.api_key', 'original-value');
    setSetting('ai.openai_api_key', '');

    bootProvider();

    expect(config('prism.providers.openai.api_key'))->toBe('original-value');
});

it('does not apply log level when setting value is empty', function () {
    Config::set('logging.channels.single.level', 'info');
    setSetting('logging.level', '');

    bootProvider();

    expect(config('logging.channels.single.level'))->toBe('info');
});

// ---------------------------------------------------------------------------
// Mail map
// ---------------------------------------------------------------------------

it('applies mail driver from settings to config', function () {
    setSetting('mail.mailer', 'mailgun');

    bootProvider();

    expect(config('mail.default'))->toBe('mailgun');
});

it('applies smtp host and port from settings to config', function () {
    setSetting('mail.host', 'smtp.sendgrid.net');
    setSetting('mail.port', '465');

    bootProvider();

    expect(config('mail.mailers.smtp.host'))->toBe('smtp.sendgrid.net');
    expect(config('mail.mailers.smtp.port'))->toBe('465');
});

it('applies smtp credentials from settings to config', function () {
    setSetting('mail.username', 'apikey');
    setSetting('mail.password', 'sg-secret');

    bootProvider();

    expect(config('mail.mailers.smtp.username'))->toBe('apikey');
    expect(config('mail.mailers.smtp.password'))->toBe('sg-secret');
});

it('applies from address and name from settings to config', function () {
    setSetting('mail.from_address', 'noreply@sos-vault.com');
    setSetting('mail.from_name', 'SOS Vault');

    bootProvider();

    expect(config('mail.from.address'))->toBe('noreply@sos-vault.com');
    expect(config('mail.from.name'))->toBe('SOS Vault');
});
