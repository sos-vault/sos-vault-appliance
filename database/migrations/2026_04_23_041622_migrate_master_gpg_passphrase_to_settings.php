<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Wave\Setting;

return new class extends Migration
{
    public function up(): void
    {
        // If the passphrase is already in the settings table, do nothing.
        if (Setting::get(LICENSING_PASSPHRASE_SETTING_KEY)) {
            return;
        }

        // Pull the legacy plaintext from the environment. Earlier deployments
        // kept this in .env as SOS_MASTER_GPG_PASSPHRASE; the value is moving
        // to the settings table (encrypted with svault0).
        $plain = (string) env('SOS_MASTER_GPG_PASSPHRASE', '');

        if ($plain === '') {
            return;
        }

        $cipher = encryptLicensingPassphrase($plain);

        if ($cipher === null) {
            Log::warning(
                'migrate_master_gpg_passphrase_to_settings: svault0 unavailable; '
                .'cannot encrypt SOS_MASTER_GPG_PASSPHRASE for storage. '
                .'Set the value manually via Manage Settings → Licensing Key once the keyring is ready.'
            );

            return;
        }

        Setting::updateOrCreate(
            ['key' => LICENSING_PASSPHRASE_SETTING_KEY],
            ['display_name' => LICENSING_PASSPHRASE_SETTING_KEY, 'value' => $cipher, 'type' => 'text', 'order' => 0]
        );

        Log::notice(
            'migrate_master_gpg_passphrase_to_settings: SOS_MASTER_GPG_PASSPHRASE '
            .'has been migrated to the settings table (encrypted with svault0). '
            .'You can now remove SOS_MASTER_GPG_PASSPHRASE from .env.'
        );
    }

    public function down(): void
    {
        Setting::where('key', LICENSING_PASSPHRASE_SETTING_KEY)->delete();
    }
};
