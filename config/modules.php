<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GPG Home Directory (app-side)
    |--------------------------------------------------------------------------
    |
    | Path to the GPG keyring used by the application when verifying and
    | decrypting signed module packages (.tar.gz.gpg). This keyring should
    | contain the public key that corresponds to the private key used to
    | sign modules at build time.
    |
    | Default: the .gnupg directory at the project root.
    |
    */
    'gpg_home' => env('MODULES_GPG_HOME', base_path('.gnupg')),
];
