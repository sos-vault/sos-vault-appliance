<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * Encrypt / decrypt the master GPG (licensing) passphrase using the svault0
 * keyring key. Lives in App\Services so tests can shadow getSvaultKey() via
 * the namespace-level stub in tests/Support/SvaultKeyStub.php.
 */
class LicensingPassphraseService
{
    /**
     * Encrypt a plaintext passphrase. Returns null when the input is empty
     * or the svault0 key is unavailable.
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
     * Decrypt a stored ciphertext. Returns an empty string when the input
     * is empty, the svault0 key is unavailable, or decryption fails.
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
            Log::error('LicensingPassphraseService::decrypt failed: '.$e->getMessage());

            return '';
        }
    }

    private function encrypter(): ?Encrypter
    {
        $key = getSvaultKey('svault0');

        if (! $key || strlen($key) !== 32) {
            Log::error('LicensingPassphraseService: svault0 key unavailable or wrong length');

            return null;
        }

        return new Encrypter(key: $key, cipher: config('app.cipher'));
    }
}
