<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * Encrypt / decrypt secret settings-table values (cloud AI provider API keys,
 * the ServiceNow/ITSM password, the AWS/S3 secret access key) using the
 * svault0 keyring key. Lives in App\Services — and calls the unqualified
 * getSvaultKey() — so tests can shadow the keyring via the namespace-level
 * stub in tests/Support/SvaultKeyStub.php, exactly like LicensingPassphraseService
 * and SiemSettingsService.
 */
class SettingsEncryptionService
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
            Log::error('SettingsEncryptionService::decrypt failed: '.$e->getMessage());

            return '';
        }
    }

    /**
     * Decrypt a stored value, falling back to the raw input when it isn't
     * valid ciphertext. These settings held plaintext before encryption at
     * rest was added, so an install upgrading in place still has plaintext
     * rows — treating a decrypt failure as "unset" would silently break an
     * already-configured AI/ServiceNow/AWS credential until the admin
     * happens to re-save it.
     */
    public function decryptOrRaw(?string $cipher): string
    {
        if (! $cipher) {
            return '';
        }

        $encrypter = $this->encrypter();

        if (! $encrypter) {
            return $cipher;
        }

        try {
            return $encrypter->decrypt($cipher);
        } catch (DecryptException) {
            return $cipher;
        }
    }

    private function encrypter(): ?Encrypter
    {
        $key = getSvaultKey('svault0');

        if (! $key || strlen($key) !== 32) {
            Log::error('SettingsEncryptionService: svault0 key unavailable or wrong length');

            return null;
        }

        return new Encrypter(key: $key, cipher: config('app.cipher'));
    }
}
