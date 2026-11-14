<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * Encrypt / decrypt SIEM Integration settings using the svault0 keyring key.
 *
 * Every siem.* value (host, port, protocol, format, and the TLS certificates)
 * is stored encrypted at rest. Lives in App\Services — and calls the
 * unqualified getSvaultKey() — so tests can shadow the keyring via the
 * namespace-level stub in tests/Support/SvaultKeyStub.php, exactly like
 * LicensingPassphraseService.
 */
class SiemSettingsService
{
    /**
     * Encrypt a plaintext value. Returns null when the input is empty or the
     * svault0 key is unavailable.
     */
    public function encrypt(string $plain): ?string
    {
        if ($plain === '') {
            return null;
        }

        $encrypter = $this->encrypter();

        return $encrypter?->encrypt($plain);
    }

    /**
     * Decrypt a stored ciphertext. Returns an empty string when the input is
     * empty, the svault0 key is unavailable, or decryption fails.
     */
    public function decrypt(?string $cipher): string
    {
        if (! $cipher) {
            return '';
        }

        $encrypter = $this->encrypter();

        if (! $encrypter) {
            return '';
        }

        try {
            return $encrypter->decrypt($cipher);
        } catch (DecryptException $e) {
            Log::error('SiemSettingsService::decrypt failed: '.$e->getMessage());

            return '';
        }
    }

    private function encrypter(): ?Encrypter
    {
        $key = getSvaultKey('svault0');

        if (! $key || strlen($key) !== 32) {
            Log::error('SiemSettingsService: svault0 key unavailable or wrong length');

            return null;
        }

        return new Encrypter(key: $key, cipher: config('app.cipher'));
    }
}
