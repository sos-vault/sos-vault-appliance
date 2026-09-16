<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Restricted from '*': the app's own UI calls its API same-origin (CORS does
    // not apply), so limiting cross-origin to the canonical hosts breaks no
    // first-party usage while denying arbitrary cross-origin readers. Appliance
    // boxes are reached same-origin by IP/hostname, so they are unaffected too.
    'allowed_origins' => ['https://sos-vault.com', 'https://www.sos-vault.com'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
