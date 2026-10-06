<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            ['key' => 'logging.channel',              'display_name' => 'Default Log Channel',      'value' => 'stack', 'type' => 'text', 'order' => 1, 'group' => 'Logging'],
            ['key' => 'logging.level',                'display_name' => 'Log Level',                'value' => 'debug', 'type' => 'text', 'order' => 2, 'group' => 'Logging'],
            ['key' => 'logging.deprecations_channel', 'display_name' => 'Deprecations Log Channel', 'value' => 'null',  'type' => 'text', 'order' => 3, 'group' => 'Logging'],
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
            'logging.channel',
            'logging.level',
            'logging.deprecations_channel',
        ])->delete();
    }
};
