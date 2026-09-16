<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            ['key' => 'redis.host',     'display_name' => 'Redis Host',     'value' => 'redis',  'type' => 'text',     'order' => 1, 'group' => 'Redis'],
            ['key' => 'redis.port',     'display_name' => 'Redis Port',     'value' => '6379',   'type' => 'text',     'order' => 2, 'group' => 'Redis'],
            ['key' => 'redis.password', 'display_name' => 'Redis Password', 'value' => '',       'type' => 'password', 'order' => 3, 'group' => 'Redis'],
            ['key' => 'redis.client',   'display_name' => 'Redis Client',   'value' => 'predis', 'type' => 'text',     'order' => 4, 'group' => 'Redis'],
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
            'redis.host',
            'redis.port',
            'redis.password',
            'redis.client',
        ])->delete();
    }
};
