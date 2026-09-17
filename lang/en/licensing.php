<?php

/**
 * Open-core licensing strings — shared across appliance License page,
 * Disk Manager (unlicensed view), dashboard widget callout, the login-block
 * flash, and the User::creating() guard exception.
 */
return [

    'status' => [
        'active' => 'ACTIVE',
        'expired' => 'EXPIRED',
        'revoked' => 'REVOKED',
        'none' => 'NONE',
    ],

    'banner' => [
        'unlicensed_summary' => 'Running in open-core baseline: single admin, plain vault directory. Install a license to unlock multi-user, groups, modules, ITSM, encrypted vaults, and the event log.',
        'expired_summary' => 'License expired. Multi-user and licensed features are temporarily disabled. Renew to restore access.',
        'cta_install' => 'Install license',
    ],

    'request' => [
        'section_heading' => 'Request a License',
        'section_description' => 'Generate a short key bound to this server\'s hardware. Copy it and paste it at sos-vault.com (Verify License Request) to purchase a license that matches this exact server.',
        'button_generate' => 'Generate License Request',
        'button_copy' => 'Copy key',
        'button_copied' => 'Copied',
        'key_heading' => 'Your License Request Key',
        'key_helper' => 'Copy this key and paste it at sos-vault.com under "Verify License Request". It is safe to share — it contains only this server\'s hardware fingerprint.',
        'notif_key_ready' => 'License request key ready',
        'notif_key_ready_body' => 'Copy the key below and paste it at sos-vault.com under "Verify License Request".',
        'notif_failed' => 'Could not generate license request',
    ],

    'expired_non_admin_blocked' => 'This appliance does not currently have an active license. Only the administrator can sign in. Ask your operator to renew or install a license.',

    'user_creating_single_admin' => 'Open-core baseline allows a single admin user. Install a license to add more users.',

    'modules_unavailable' => 'Module installation requires an active license.',
    'event_log_unavailable' => 'The Event Log is only available on a licensed appliance.',

    'disk_manager' => [
        'unlicensed_title' => 'Vault Directory',
        'vault_dir_label' => 'Path to the vault directory',
        'vault_dir_helper' => 'Open-core baseline uses a plain (non-encrypted) directory as the vault. Default: /vault. Must be an absolute path on the host.',
        'save_button' => 'Save',
        'save_notif' => 'Vault directory saved.',
    ],

    'dashboard' => [
        'unlicensed_title' => 'License',
        'unlicensed_value' => 'OPEN-CORE',
        'unlicensed_callout' => 'Install a license to unlock multi-user, groups, modules, ITSM, encrypted vaults, and the event log.',
    ],

    'event' => [
        'request_generated' => 'License request generated',
        'license_installed' => 'License installed',
        'license_expired' => 'License expired',
        'login_blocked' => 'Non-admin login blocked (unlicensed)',
    ],

];
