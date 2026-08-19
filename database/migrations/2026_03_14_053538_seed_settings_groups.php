<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            // Site
            ['key' => 'site.app_name',   'display_name' => 'Application Name',   'value' => 'sos-vault', 'type' => 'text',     'order' => 3,  'group' => 'Site'],
            ['key' => 'site.app_url',    'display_name' => 'Application URL',     'value' => '',          'type' => 'text',     'order' => 4,  'group' => 'Site'],
            ['key' => 'site.app_version', 'display_name' => 'Application Version', 'value' => '2.0.0',    'type' => 'text',     'order' => 5,  'group' => 'Site'],

            // Billing
            ['key' => 'billing.provider',                  'display_name' => 'Billing Provider',                   'value' => 'paddle', 'type' => 'text',     'order' => 1,  'group' => 'Billing'],
            ['key' => 'billing.paddle_env',                'display_name' => 'Paddle Environment',                 'value' => 'sandbox', 'type' => 'text',     'order' => 2,  'group' => 'Billing'],
            ['key' => 'billing.paddle_vendor_id',          'display_name' => 'Paddle Vendor ID',                   'value' => '',       'type' => 'text',     'order' => 3,  'group' => 'Billing'],
            ['key' => 'billing.paddle_api_key',            'display_name' => 'Paddle API Key',                     'value' => '',       'type' => 'password', 'order' => 4,  'group' => 'Billing'],
            ['key' => 'billing.paddle_client_side_token',  'display_name' => 'Paddle Client Side Token',           'value' => '',       'type' => 'password', 'order' => 5,  'group' => 'Billing'],
            ['key' => 'billing.paddle_public_key',         'display_name' => 'Paddle Public Key',                  'value' => '',       'type' => 'password', 'order' => 6,  'group' => 'Billing'],
            ['key' => 'billing.paddle_webhook_secret',     'display_name' => 'Paddle Webhook Secret',              'value' => '',       'type' => 'password', 'order' => 7,  'group' => 'Billing'],
            ['key' => 'billing.stripe_publishable_key',    'display_name' => 'Stripe Publishable Key',             'value' => '',       'type' => 'text',     'order' => 8,  'group' => 'Billing'],
            ['key' => 'billing.stripe_secret_key',         'display_name' => 'Stripe Secret Key',                  'value' => '',       'type' => 'password', 'order' => 9,  'group' => 'Billing'],
            ['key' => 'billing.stripe_webhook_secret',     'display_name' => 'Stripe Webhook Secret',              'value' => '',       'type' => 'password', 'order' => 10, 'group' => 'Billing'],

            // Analytics
            ['key' => 'analytics.youtube_api_key_1', 'display_name' => 'YouTube API Key 1', 'value' => '', 'type' => 'password', 'order' => 2, 'group' => 'Analytics'],
            ['key' => 'analytics.youtube_api_key_2', 'display_name' => 'YouTube API Key 2', 'value' => '', 'type' => 'password', 'order' => 3, 'group' => 'Analytics'],

            // OAuth
            ['key' => 'oauth.google_client_id',       'display_name' => 'Google Client ID',       'value' => '', 'type' => 'text',     'order' => 1, 'group' => 'OAuth'],
            ['key' => 'oauth.google_client_secret',   'display_name' => 'Google Client Secret',   'value' => '', 'type' => 'password', 'order' => 2, 'group' => 'OAuth'],
            ['key' => 'oauth.facebook_client_id',     'display_name' => 'Facebook Client ID',     'value' => '', 'type' => 'text',     'order' => 3, 'group' => 'OAuth'],
            ['key' => 'oauth.facebook_client_secret', 'display_name' => 'Facebook Client Secret', 'value' => '', 'type' => 'password', 'order' => 4, 'group' => 'OAuth'],
            ['key' => 'oauth.github_client_id',       'display_name' => 'GitHub Client ID',       'value' => '', 'type' => 'text',     'order' => 5, 'group' => 'OAuth'],
            ['key' => 'oauth.github_client_secret',   'display_name' => 'GitHub Client Secret',   'value' => '', 'type' => 'password', 'order' => 6, 'group' => 'OAuth'],

            // AI
            ['key' => 'ai.openai_api_key', 'display_name' => 'OpenAI API Key', 'value' => '', 'type' => 'password', 'order' => 1, 'group' => 'AI'],

            // Telegram
            ['key' => 'telegram.api_key', 'display_name' => 'Telegram API Key', 'value' => '', 'type' => 'password', 'order' => 1, 'group' => 'Telegram'],
            ['key' => 'telegram.chat_id', 'display_name' => 'Telegram Chat ID', 'value' => '', 'type' => 'text',     'order' => 2, 'group' => 'Telegram'],

            // Security
            ['key' => 'security.recaptcha_site_key',   'display_name' => 'reCAPTCHA Site Key',    'value' => '', 'type' => 'text',     'order' => 1, 'group' => 'Security'],
            ['key' => 'security.recaptcha_secret_key', 'display_name' => 'reCAPTCHA Secret Key',  'value' => '', 'type' => 'password', 'order' => 2, 'group' => 'Security'],
            ['key' => 'security.maxmind_license_key',  'display_name' => 'MaxMind License Key',   'value' => '', 'type' => 'password', 'order' => 3, 'group' => 'Security'],
            ['key' => 'security.abuseip_storage_path',     'display_name' => 'AbuseIPDB Storage Path',     'value' => 'framework/cache/abuseip.json', 'type' => 'text', 'order' => 4, 'group' => 'Security'],
            ['key' => 'security.abuseip_storage_compress', 'display_name' => 'AbuseIPDB Compress Storage', 'value' => 'true',                         'type' => 'text', 'order' => 5, 'group' => 'Security'],

            // ServiceNow
            ['key' => 'servicenow.instance', 'display_name' => 'ServiceNow Instance URL', 'value' => '', 'type' => 'text',     'order' => 1, 'group' => 'ServiceNow'],
            ['key' => 'servicenow.username', 'display_name' => 'ServiceNow Username',     'value' => '', 'type' => 'text',     'order' => 2, 'group' => 'ServiceNow'],
            ['key' => 'servicenow.password', 'display_name' => 'ServiceNow Password',     'value' => '', 'type' => 'password', 'order' => 3, 'group' => 'ServiceNow'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['details' => null])
            );
        }

        // Rename existing analytics setting key to match group convention (skip if target already exists)
        $targetExists = DB::table('settings')->where('key', 'analytics.ga_property_id')->exists();

        if (! $targetExists) {
            DB::table('settings')
                ->where('key', 'site.google_analytics_tracking_id')
                ->update(['key' => 'analytics.ga_property_id', 'display_name' => 'Google Analytics Property ID', 'group' => 'Analytics', 'order' => 1]);
        } else {
            DB::table('settings')->where('key', 'site.google_analytics_tracking_id')->delete();
        }
    }

    public function down(): void
    {
        $keys = [
            'site.app_name', 'site.app_url', 'site.app_version',
            'billing.provider', 'billing.paddle_env', 'billing.paddle_vendor_id',
            'billing.paddle_api_key', 'billing.paddle_client_side_token',
            'billing.paddle_public_key', 'billing.paddle_webhook_secret',
            'billing.stripe_publishable_key', 'billing.stripe_secret_key', 'billing.stripe_webhook_secret',
            'analytics.ga_property_id', 'analytics.youtube_api_key_1', 'analytics.youtube_api_key_2',
            'oauth.google_client_id', 'oauth.google_client_secret',
            'oauth.facebook_client_id', 'oauth.facebook_client_secret',
            'oauth.github_client_id', 'oauth.github_client_secret',
            'ai.openai_api_key',
            'telegram.api_key', 'telegram.chat_id',
            'security.recaptcha_site_key', 'security.recaptcha_secret_key',
            'security.maxmind_license_key', 'security.abuseip_storage_path', 'security.abuseip_storage_compress',
            'servicenow.instance', 'servicenow.username', 'servicenow.password',
        ];

        DB::table('settings')->whereIn('key', $keys)->delete();

        DB::table('settings')
            ->where('key', 'analytics.ga_property_id')
            ->update(['key' => 'site.google_analytics_tracking_id', 'display_name' => 'Google Analytics Tracking ID', 'group' => 'Site', 'order' => 4]);
    }
};
