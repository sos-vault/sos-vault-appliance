<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            ['key' => 'aws.access_key_id',          'display_name' => 'AWS Access Key ID',          'value' => '',          'type' => 'text',     'order' => 1, 'group' => 'AWS'],
            ['key' => 'aws.secret_access_key',       'display_name' => 'AWS Secret Access Key',      'value' => '',          'type' => 'password', 'order' => 2, 'group' => 'AWS'],
            ['key' => 'aws.default_region',          'display_name' => 'AWS Default Region',         'value' => 'us-east-1', 'type' => 'text',     'order' => 3, 'group' => 'AWS'],
            ['key' => 'aws.bucket',                  'display_name' => 'S3 Bucket Name',             'value' => '',          'type' => 'text',     'order' => 4, 'group' => 'AWS'],
            ['key' => 'aws.url',                     'display_name' => 'S3 Custom URL',              'value' => '',          'type' => 'text',     'order' => 5, 'group' => 'AWS'],
            ['key' => 'aws.endpoint',                'display_name' => 'S3 Endpoint (custom/MinIO)', 'value' => '',          'type' => 'text',     'order' => 6, 'group' => 'AWS'],
            ['key' => 'aws.use_path_style_endpoint', 'display_name' => 'Use Path-Style Endpoint',   'value' => 'false',     'type' => 'text',     'order' => 7, 'group' => 'AWS'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['details' => null])
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'aws.access_key_id', 'aws.secret_access_key', 'aws.default_region',
            'aws.bucket', 'aws.url', 'aws.endpoint', 'aws.use_path_style_endpoint',
        ])->delete();
    }
};
