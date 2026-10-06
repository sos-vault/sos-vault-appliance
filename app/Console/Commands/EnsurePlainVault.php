<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Wave\Setting;

/**
 * Ensure the /vault mount root exists on appliance installs. Called from
 * installer.sh on first boot AND available ad-hoc if the admin moves the vault
 * directory via the "Main Vault" section of the Manage Settings page.
 *
 * Note: this only guarantees the parent directory. The admin's data is NOT
 * stored loose in this plain directory — the admin always gets a personal
 * LUKS-encrypted vault whose .img and mountpoint live *inside* /vault
 * (provisioned by VaultTools::createPersonalVault on first admin login),
 * licensed or not. This command just makes sure that root exists first.
 *
 * Reads appliance.vault_dir from the settings table (falls back to
 * config('appliance.vault_dir', '/vault')). The directory is created
 * with mode 0750 owned by the current process user — under sudo invocation
 * from installer.sh this resolves to root:root, which is fine for a plain
 * baseline vault (sos-vault writes via the app container, which has its own
 * volume mount).
 */
class EnsurePlainVault extends Command
{
    protected $signature = 'sos-vault:ensure-plain-vault';

    protected $description = 'Create the open-core plain vault directory (no LUKS) if it does not exist.';

    public function handle(): int
    {
        if (! isAppliance()) {
            $this->error('sos-vault:ensure-plain-vault only runs on appliance installs (config product.type=appliance).');

            return 1;
        }

        $path = (string) Setting::get('appliance.vault_dir', config('appliance.vault_dir', '/vault'));

        if ($path === '' || $path[0] !== '/') {
            $this->error("Refusing to create vault directory at non-absolute path: {$path}");

            return 1;
        }

        if (! is_dir($path)) {
            if (! @mkdir($path, 0750, true)) {
                $this->error("Failed to create vault directory: {$path}");

                return 1;
            }
            $this->info("Created plain vault directory: {$path}");
        } else {
            $this->info("Plain vault directory already exists: {$path}");
        }

        // Ensure the perms are 0750 even if the directory was pre-existing.
        @chmod($path, 0750);

        return 0;
    }
}
