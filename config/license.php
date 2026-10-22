<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GPG Home for License Signing (SaaS side)
    |--------------------------------------------------------------------------
    |
    | Path to the GPG keyring containing the private key used to clearsign
    | license files. Reuses the same keyring as module signing.
    |
    */
    'gpg_home_sign' => env('GPG_HOME_BUILD', base_path('.gnupg')),

    /*
    |--------------------------------------------------------------------------
    | GPG Home for License Verification (appliance side)
    |--------------------------------------------------------------------------
    |
    | Path to the GPG keyring containing only the public key, used to verify
    | license signatures on the appliance. Defaults to the same keyring as
    | module verification (MODULES_GPG_HOME).
    |
    */
    'gpg_home_verify' => env('LICENSE_GPG_HOME', env('MODULES_GPG_HOME', base_path('.gnupg'))),

    /*
    |--------------------------------------------------------------------------
    | Grace Period (days)
    |--------------------------------------------------------------------------
    |
    | Number of days after license expiry before features are fully locked.
    | Gives the customer time to renew and upload a new license.
    |
    */
    'grace_period_days' => (int) env('LICENSE_GRACE_PERIOD_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Paddle Checkout Return URL
    |--------------------------------------------------------------------------
    |
    | After the customer completes (or cancels) Paddle checkout they are
    | redirected back to this URL. Webhook minting is independent of this
    | redirect — the License is created when Paddle delivers
    | transaction.completed, regardless of whether the user landed back here
    | first. The appliance has no in-app checkout (billing is SaaS-only and
    | 404'd here), so the default just points at the admin License page.
    |
    */
    'paddle_return_url' => env('LICENSE_RETURN_URL', '/admin/manage-license'),

    /*
    |--------------------------------------------------------------------------
    | License Purchase Intent TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | Pending intents older than this are considered abandoned and will be
    | rejected when a late webhook arrives. Default: 60 minutes.
    |
    */
    'intent_ttl_minutes' => (int) env('LICENSE_INTENT_TTL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Support GPG Recipient (appliance side)
    |--------------------------------------------------------------------------
    |
    | User-id (typically email) of the public key inside gpg_home_verify that
    | sos-vault:capture-server-report encrypts the captured sosreport to.
    | The build pipeline imports the SaaS support team's pubkey into the
    | appliance image's keyring under this UID. Only the holder of the
    | matching private key (SaaS support) can decrypt the resulting tarball.
    | Hardcoded — no env() override per the project settings-table rule.
    |
    */
    'support_recipient' => 'support@sos-vault.com',

    /*
    |--------------------------------------------------------------------------
    | License-Request GPG Recipient (appliance → SaaS)
    |--------------------------------------------------------------------------
    |
    | UID inside gpg_home_verify that the appliance encrypts license-request
    | sosreports to. Reuses the master modules-signing keypair (same UID,
    | same passphrase, same .gnupg/ keyring) — one key serves module
    | distribution AND license signing/decryption. Splitting these is a
    | post-launch scaling decision, not a blocker. Hardcoded — no env()
    | override per the project settings-table rule.
    |
    */
    'issuer_recipient' => 'jlrueda@sos-vault.com',

];
