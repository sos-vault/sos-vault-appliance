<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * Encrypt / decrypt a per-Alert contact-point secret (currently: the Slack
 * incoming-webhook URL) using the svault0 keyring key. Same shape as
 * LicensingPassphraseService / SiemSettingsService — a dedicated service per
 * secret domain, kept in App\Services so tests can shadow getSvaultKey() via
 * the namespace-level stub in tests/Support/SvaultKeyStub.php.
 */
class AlertContactPointEncryptionService
{
    public function encrypt(string $plain): ?string
    {
        if ($plain === '') {
            return null;
        }

        $encrypter = $this->encrypter();

        return $encrypter?->encrypt($plain);
    }

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
            Log::error('AlertContactPointEncryptionService::decrypt failed: '.$e->getMessage());

            return '';
        }
    }

    private function encrypter(): ?Encrypter
    {
        $key = getSvaultKey('svault0');

        if (! $key || strlen($key) !== 32) {
            Log::error('AlertContactPointEncryptionService: svault0 key unavailable or wrong length');

            return null;
        }

        return new Encrypter(key: $key, cipher: config('app.cipher'));
    }
}
