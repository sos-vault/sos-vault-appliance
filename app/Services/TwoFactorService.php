<?php

namespace App\Services;

use App\Models\User;
use chillerlan\Authenticator\Authenticator;
use chillerlan\Authenticator\AuthenticatorOptions;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TOTP two-factor authentication (RFC 6238).
 *
 * Fully offline by design — no network at enrollment or verification — which is
 * why it is the right second factor for an airgapped appliance (unlike email
 * codes). The QR is rendered locally via chillerlan/php-qrcode (no external
 * chart API). The clock-drift tolerance is configurable for boxes without NTP.
 *
 * Secrets and recovery-code hashes are encrypted at rest in the existing
 * two_factor_* user columns.
 */
class TwoFactorService
{
    /**
     * Issuer shown in the authenticator app. Distinct per environment so the
     * same admin can enroll prod, dev and an appliance in one Google
     * Authenticator without the three entries colliding.
     */
    public function issuer(): string
    {
        if (isAppliance()) {
            return 'sos-vault-self-hosted';
        }

        return app()->environment('production') ? 'sos-vault' : 'sos-vault-dev';
    }

    /**
     * Adjacent time-steps accepted on each side of "now" (1 = ±30s). Widen via
     * the settings table on airgapped hosts whose clock drifts without NTP.
     */
    public function driftWindow(): int
    {
        return max(0, (int) setting('auth.two_factor_window', 1));
    }

    public function generateSecret(): string
    {
        return $this->authenticator()->createSecret();
    }

    /** otpauth:// URI for the enrollment QR / manual entry. */
    public function otpauthUri(string $secret, string $account): string
    {
        return $this->authenticator()->setSecret($secret)->getUri($account, $this->issuer());
    }

    /** Inline base64 SVG data-URI — rendered locally, safe for airgapped use. */
    public function qrCodeDataUri(string $otpauthUri): string
    {
        return (new QRCode)->render($otpauthUri);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        try {
            return $this->authenticator()->setSecret($secret)->verify($code);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> plaintext recovery codes (shown once at enrollment) */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn (): string => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * Persist a confirmed enrollment: encrypted secret + hashed recovery codes.
     *
     * @param  list<string>  $recoveryCodes  plaintext codes
     */
    public function enable(User $user, string $secret, array $recoveryCodes): void
    {
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(
                json_encode(array_map(fn (string $c): string => Hash::make($c), $recoveryCodes))
            ),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->recordEvent($user, 'ENABLE_2FA');
    }

    public function disable(User $user): void
    {
        // Only audit a real state change: a no-op disable (already off, e.g. a
        // double submit or a break-glass on an unenrolled account) emits nothing.
        $wasEnabled = $user->hasTwoFactorEnabled();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        if ($wasEnabled) {
            $this->recordEvent($user, 'DISABLE_2FA');
        }
    }

    /**
     * Audit a 2FA enable/disable to the Event Log (and thus the SIEM). owner is
     * the acting user when there is a session, falling back to the target user
     * for the break-glass CLI path; the target is always named in the payload.
     */
    private function recordEvent(User $user, string $type): void
    {
        addEvent(
            ['user_id' => $user->id, 'email' => $user->email],
            $type,
            'SUCCESS',
            'ACTIVITY',
            0,
            0,
            auth()->id() ?: $user->id,
            $user->id,
        );
    }

    /**
     * Verify a login challenge: a TOTP code first, then a one-time recovery
     * code (which is consumed on success).
     */
    public function verifyForUser(User $user, string $code): bool
    {
        $secret = $this->userSecret($user);

        if ($secret !== null && $this->verifyCode($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function userSecret(User $user): ?string
    {
        if (blank($user->two_factor_secret)) {
            return null;
        }

        try {
            return Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable) {
            return null;
        }
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = trim($code);

        if ($code === '' || blank($user->two_factor_recovery_codes)) {
            return false;
        }

        try {
            $hashes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: [];
        } catch (\Throwable) {
            return false;
        }

        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill([
                    'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($hashes))),
                ])->save();

                return true;
            }
        }

        return false;
    }

    private function authenticator(): Authenticator
    {
        return new Authenticator(new AuthenticatorOptions([
            'adjacent' => $this->driftWindow(),
        ]));
    }
}
