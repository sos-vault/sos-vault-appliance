<?php

/**
 * sos-vault:store-machine-fingerprint — the artisan command the installer
 * (Step 13b) runs in the container with the host identifiers in INSTALLER_FP_*
 * env. SvaultKeyStub provides the svault0 key so the store can encrypt.
 */

require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Services\MachineTokenService;
use Wave\Setting;

afterEach(function () {
    foreach (['MACHINE_ID', 'DMI_UUID', 'BOARD_SERIAL', 'SYSTEM_SERIAL'] as $k) {
        putenv("INSTALLER_FP_{$k}");
    }
});

it('stores the fingerprint from INSTALLER_FP_* env', function () {
    putenv('INSTALLER_FP_MACHINE_ID=cmd-mid');
    putenv('INSTALLER_FP_DMI_UUID=cmd-uuid');
    putenv('INSTALLER_FP_BOARD_SERIAL=');
    putenv('INSTALLER_FP_SYSTEM_SERIAL=');

    $this->artisan('sos-vault:store-machine-fingerprint')->assertExitCode(0);

    $stored = Setting::where('key', MachineTokenService::FINGERPRINT_SETTING_KEY)->value('value');
    expect($stored)->not->toBeNull();

    // Two usable identifiers → two tokens on read-back.
    expect((new MachineTokenService)->currentHostTokens())->toHaveCount(2);
});

it('fails when no identifiers are present in the env', function () {
    putenv('INSTALLER_FP_MACHINE_ID=');
    putenv('INSTALLER_FP_DMI_UUID=');
    putenv('INSTALLER_FP_BOARD_SERIAL=');
    putenv('INSTALLER_FP_SYSTEM_SERIAL=');

    $this->artisan('sos-vault:store-machine-fingerprint')->assertExitCode(1);

    expect(Setting::where('key', MachineTokenService::FINGERPRINT_SETTING_KEY)->exists())->toBeFalse();
});
