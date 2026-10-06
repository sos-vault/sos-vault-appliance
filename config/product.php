<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product Type
    |--------------------------------------------------------------------------
    |
    | Controls which features and panels are active in this deployment.
    |
    | 'saas'      - sos-vault.com hosted service (master branch)
    | 'appliance' - self-hosted on-premises edition (appliance branch)
    |
    */
    'type' => 'appliance',

    /*
    |--------------------------------------------------------------------------
    | Self-Hosted Report Master Key
    |--------------------------------------------------------------------------
    |
    | When self-hosted customers upload SOS reports via the Customer Portal,
    | the files are encrypted with the SaaS public key. The SaaS server uses
    | the matching private key (stored in GPG_HOME_BUILD keyring) to decrypt
    | them before ingesting into the admin vault.
    |
    | The passphrase that unlocks the master key is NOT read from the
    | environment. It is stored encrypted (svault0) in the settings table
    | under 'licensing.master_gpg_passphrase' and managed from the
    | "Licensing Key" section of the admin Manage Settings page. Use
    | getMasterGpgPassphrase() to retrieve the plaintext at runtime.
    |
    */
    'master_gpg_home' => env('GPG_HOME_BUILD', base_path('.gnupg')),

];
