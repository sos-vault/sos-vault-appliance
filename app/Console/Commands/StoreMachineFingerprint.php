<?php

namespace App\Console\Commands;

use App\Services\MachineTokenService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Capture this host's hardware fingerprint into the encrypted settings store.
 *
 * Run by the installer (Step 13b) AS the app user inside the container, AFTER
 * migrate+seed so the settings table and the svault0 keyring key both exist.
 * The installer gathers the identifiers on the HOST (where it has root + full
 * dmidecode access) and passes them in via INSTALLER_FP_* env vars — the
 * container cannot read host DMI reliably itself. The identifiers are stored
 * encrypted (svault0 key), so the fingerprint is bound to this installation.
 *
 * Re-runnable: a later run overwrites the stored fingerprint (e.g. after a
 * hardware change), which simply changes the tokens a new license request emits.
 */
class StoreMachineFingerprint extends Command
{
    protected $signature = 'sos-vault:store-machine-fingerprint';

    protected $description = 'Capture the host hardware fingerprint (from INSTALLER_FP_* env) into encrypted settings';

    public function handle(MachineTokenService $machineTokens): int
    {
        $identifiers = [
            'machine_id' => (string) getenv('INSTALLER_FP_MACHINE_ID'),
            'dmi_uuid' => (string) getenv('INSTALLER_FP_DMI_UUID'),
            'board_serial' => (string) getenv('INSTALLER_FP_BOARD_SERIAL'),
            'system_serial' => (string) getenv('INSTALLER_FP_SYSTEM_SERIAL'),
        ];

        try {
            $tokens = $machineTokens->storeFingerprint($identifiers);
        } catch (RuntimeException $e) {
            $this->error('Could not store host fingerprint: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Host fingerprint stored (%d token%s, %s binding).',
            count($tokens),
            count($tokens) === 1 ? '' : 's',
            $machineTokens->bindingStrength,
        ));

        return self::SUCCESS;
    }
}
