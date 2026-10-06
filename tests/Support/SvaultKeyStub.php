<?php

/**
 * Namespace-level shadows of the global getSvaultKey() helper.
 *
 * PHP resolves unqualified function calls by first checking the current
 * namespace (e.g. App\Services\getSvaultKey), then falling back to the
 * global function.  By defining these stubs here, any code in App\Services
 * or App\Listeners that calls getSvaultKey() will receive a deterministic
 * 32-byte test key instead of going to the Linux kernel keyring.
 *
 * The stub is loaded via require_once at the top of ITSMIntegrationTest.
 */

namespace App\Services {
    function getSvaultKey(string $description): string
    {
        // Set $GLOBALS['__svault_stub_empty'] = true to simulate a host whose
        // kernel keyring lost the svault* keys (getSvaultKey returns '').
        if ($GLOBALS['__svault_stub_empty'] ?? false) {
            return '';
        }

        // 32 bytes → valid for AES-256-CBC and AES-256-GCM
        return str_repeat('T', 32);
    }
}

namespace App\Listeners {
    function getSvaultKey(string $description): string
    {
        if ($GLOBALS['__svault_stub_empty'] ?? false) {
            return '';
        }

        return str_repeat('T', 32);
    }
}
