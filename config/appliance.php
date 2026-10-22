<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vault directory
    |--------------------------------------------------------------------------
    |
    | Default filesystem path where sos-vault stores its vaults. A plain
    | directory is all that is required — no ZFS. The operator may override
    | this on the Disk Manager page (persisted to the settings key
    | appliance.vault_dir) and it is provisioned by the
    | sos-vault:ensure-plain-vault command.
    |
    */
    'vault_dir' => '/vault',

    /*
    |--------------------------------------------------------------------------
    | cert-helper script path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the privileged TLS / nginx wrapper. CertificateManager
    | invokes this through Illuminate\Support\Facades\Process so tests can
    | rebind the path or fake the response.
    |
    */
    'cert_helper' => base_path('sysadmin/cert-helper'),

    /*
    |--------------------------------------------------------------------------
    | machine-token-helper script path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the privileged dmidecode wrapper used by
    | MachineTokenService::currentHostTokens() to bind the installed license
    | more tightly to the live host. The helper exits with the board / product
    | serial on stdout, or the literal "UNKNOWN" when both sources are
    | placeholder values. Returning UNKNOWN downgrades license install to
    | "weak binding" semantics (primary machine-id token only) — the
    | appliance is intentionally permissive here so a blank-DMI VM does
    | not refuse a legitimate install.
    |
    */
    'machine_token_helper' => base_path('sysadmin/machine-token-helper'),

];
