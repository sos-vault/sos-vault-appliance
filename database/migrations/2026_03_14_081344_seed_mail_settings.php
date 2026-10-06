<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            ['key' => 'mail.mailer',       'display_name' => 'Mail Driver',         'value' => 'smtp',              'type' => 'text',     'order' => 1, 'group' => 'Mail'],
            ['key' => 'mail.host',         'display_name' => 'SMTP Host',           'value' => 'smtp.mailgun.org',  'type' => 'text',     'order' => 2, 'group' => 'Mail'],
            ['key' => 'mail.port',         'display_name' => 'SMTP Port',           'value' => '587',               'type' => 'text',     'order' => 3, 'group' => 'Mail'],
            ['key' => 'mail.encryption',   'display_name' => 'SMTP Encryption',     'value' => 'tls',               'type' => 'text',     'order' => 4, 'group' => 'Mail'],
            ['key' => 'mail.username',     'display_name' => 'SMTP Username',       'value' => '',                  'type' => 'text',     'order' => 5, 'group' => 'Mail'],
            ['key' => 'mail.password',     'display_name' => 'SMTP Password',       'value' => '',                  'type' => 'password', 'order' => 6, 'group' => 'Mail'],
            ['key' => 'mail.from_address', 'display_name' => 'From Address',        'value' => 'hello@example.com', 'type' => 'text',     'order' => 7, 'group' => 'Mail'],
            ['key' => 'mail.from_name',    'display_name' => 'From Name',           'value' => '',                  'type' => 'text',     'order' => 8, 'group' => 'Mail'],
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
            'mail.mailer', 'mail.host', 'mail.port', 'mail.encryption',
            'mail.username', 'mail.password', 'mail.from_address', 'mail.from_name',
        ])->delete();
    }
};
