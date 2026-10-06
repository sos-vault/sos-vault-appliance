<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class BillingSettingsServiceProvider extends ServiceProvider
{
    /**
     * Maps settings table keys to wave config paths.
     *
     * @var array<string, string>
     */
    private const BILLING_MAP = [
        'billing.provider' => 'wave.billing_provider',
        'billing.paddle_env' => 'wave.paddle.env',
        'billing.paddle_vendor_id' => 'wave.paddle.vendor',
        'billing.paddle_api_key' => 'wave.paddle.api_key',
        'billing.paddle_client_side_token' => 'wave.paddle.client_side_token',
        'billing.paddle_public_key' => 'wave.paddle.public_key',
        'billing.paddle_webhook_secret' => 'wave.paddle.webhook_secret',
        'billing.stripe_publishable_key' => 'wave.stripe.publishable_key',
        'billing.stripe_secret_key' => 'wave.stripe.secret_key',
        'billing.stripe_webhook_secret' => 'wave.stripe.webhook_secret',
    ];

    /**
     * Maps settings table keys to services config paths (AI, Telegram, Security, ServiceNow).
     *
     * @var array<string, string>
     */
    private const SERVICES_MAP = [
        // Cloud AI provider keys for the Mil assistant (Prism). Only applied when
        // set in the settings table; otherwise the .env fallback in config/prism.php
        // (OPENAI_API_KEY / ANTHROPIC_API_KEY) stands.
        'ai.openai_api_key' => 'prism.providers.openai.api_key',
        'ai.anthropic_api_key' => 'prism.providers.anthropic.api_key',
        'telegram.api_key' => 'services.telegram.api_key',
        'telegram.chat_id' => 'services.telegram.chat_id',
        'security.recaptcha_site_key' => 'services.recaptcha.site_key',
        'security.recaptcha_secret_key' => 'services.recaptcha.secret_key',
        'security.maxmind_license_key' => 'services.maxmind.license_key',
        'security.jwt_secret' => 'jwt.secret',
        'servicenow.instance' => 'services.servicenow.instance',
        'servicenow.username' => 'services.servicenow.username',
        'servicenow.password' => 'services.servicenow.password',
    ];

    /**
     * Maps settings table keys to abuseip config paths.
     *
     * @var array<string, string>
     */
    private const ABUSEIP_MAP = [
        'security.abuseip_storage_path' => 'abuseip.storage.path',
        'security.abuseip_storage_compress' => 'abuseip.storage.compress',
    ];

    /**
     * Maps settings table keys to mail config paths.
     *
     * @var array<string, string>
     */
    private const MAIL_MAP = [
        'mail.mailer' => 'mail.default',
        'mail.host' => 'mail.mailers.smtp.host',
        'mail.port' => 'mail.mailers.smtp.port',
        'mail.encryption' => 'mail.mailers.smtp.encryption',
        'mail.username' => 'mail.mailers.smtp.username',
        'mail.password' => 'mail.mailers.smtp.password',
        'mail.from_address' => 'mail.from.address',
        'mail.from_name' => 'mail.from.name',
    ];

    /**
     * Maps settings table keys to logging config paths.
     *
     * @var array<string, string>
     */
    private const LOGGING_MAP = [
        'logging.channel' => 'logging.default',
        'logging.deprecations_channel' => 'logging.deprecations.channel',
    ];

    /** Log channels whose level is controlled by the logging.level setting. */
    private const LOG_LEVEL_CHANNELS = ['single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'];

    /**
     * Maps settings table keys to filesystems S3 config paths.
     *
     * @var array<string, string>
     */
    private const AWS_MAP = [
        'aws.access_key_id' => 'filesystems.disks.s3.key',
        'aws.secret_access_key' => 'filesystems.disks.s3.secret',
        'aws.default_region' => 'filesystems.disks.s3.region',
        'aws.bucket' => 'filesystems.disks.s3.bucket',
        'aws.url' => 'filesystems.disks.s3.url',
        'aws.endpoint' => 'filesystems.disks.s3.endpoint',
        'aws.use_path_style_endpoint' => 'filesystems.disks.s3.use_path_style_endpoint',
    ];

    public function boot(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            // Read all settings in one query to avoid N+1 and to bypass the
            // cache driver entirely (which may be Redis, unavailable in dev).
            $allMaps = [...self::BILLING_MAP, ...self::SERVICES_MAP, ...self::ABUSEIP_MAP, ...self::AWS_MAP, ...self::LOGGING_MAP, ...self::MAIL_MAP];
            $keys = array_keys($allMaps);
            $keys[] = 'logging.level';

            /** @var array<string,string> $settings */
            $settings = DB::table('settings')
                ->whereIn('key', $keys)
                ->pluck('value', 'key')
                ->all();

            foreach ($allMaps as $settingKey => $configKey) {
                $value = $settings[$settingKey] ?? null;

                if (filled($value)) {
                    if (in_array($settingKey, ENCRYPTED_SETTING_KEYS, true)) {
                        $value = decryptSetting($value);
                    }

                    if (filled($value)) {
                        Config::set($configKey, $value);
                    }
                }
            }

            $logLevel = $settings['logging.level'] ?? null;

            if (filled($logLevel)) {
                foreach (self::LOG_LEVEL_CHANNELS as $channel) {
                    Config::set("logging.channels.{$channel}.level", $logLevel);
                }
            }

        } catch (\Throwable) {
            // Silently ignore DB errors during early bootstrap (e.g. migrations).
        }
    }
}
