<?php

/**
 * createGroupVault() and createPersonalVault() run mkfs.ext4 on a fresh device;
 * the resulting filesystem root is owned by root:root. They must chown the
 * mountpoint to the app uid AFTER mounting — otherwise the web user (APP_UID)
 * cannot write .contents.json into the vault and the dashboard 500s with
 * "Permission denied" on first login. The legacy createVault() already chowns
 * the mountpoint; the appliance static creators had regressed to chmod-only.
 *
 * The mount/chown path only executes with real cryptsetup (skipped under
 * APP_NOVAULTS in the test env), so this asserts the source wires the chown.
 */
it('chowns the vault mountpoint to the app uid in both appliance vault creators', function () {
    $src = file_get_contents(base_path('app/Providers/VaultTools.php'));

    // The mountpoint chown must appear in BOTH createGroupVault and
    // createPersonalVault (the device-file chown alone is not enough).
    $mountChown = substr_count($src, '/bin/sudo /bin/chown {$uid}:{$gid} {$mountp}');

    expect($mountChown)->toBeGreaterThanOrEqual(2);
});
