<?php

use Illuminate\Database\Migrations\Migration;
use Wave\Setting;

return new class extends Migration
{
    public function up(): void
    {
        Setting::updateOrCreate(
            ['key' => 'site.trial_end_emails'],
            [
                'display_name' => 'Send Trial-End Reminder Emails',
                'value' => '1',
                'type' => 'text',
                'order' => 5,
                'group' => 'Site',
            ]
        );
    }

    public function down(): void
    {
        Setting::where('key', 'site.trial_end_emails')->delete();
    }
};
