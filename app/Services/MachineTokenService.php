<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use Wave\Setting;

class MachineTokenService
{
    /**
     * Settings key holding the encrypted host fingerprint — a JSON identifier
     * map captured by the installer and encrypted with the svault0 keyring key
     * (LicensingPassphraseService). Because the key is hardware/escrow-bound and
     * never leaves the box, the fingerprint cannot be decrypted on another host.
     */
    public const FINGERPRINT_SETTING_KEY = 'appliance.machine_fingerprint';

    /**
     * Identifier types captured into the fingerprint, in token order. machine-id
     * is first because it is the most copyable signal (a plain world-readable
     * file); install() treats its token as the weak primary. The others are
     * hardware-bound (DMI). No single one is mandatory — at least one is required.
     */
    private const IDENTIFIER_TYPES = ['machine_id', 'dmi_uuid', 'board_serial', 'system_serial'];

    /** Prefix tagging an encoded license-request key (versioned). */
    private const KEY_PREFIX = 'SOSV1.';

    /**
     * 'strong' when the last token derivation produced >= 2 tokens, 'weak' when
     * only one was available. Read by LocalLicenseService::install() to decide
     * whether to require a match stronger than the machine-id token alone.
     */
    public string $bindingStrength = 'weak';

    /**
     * The machine-id-derived token from the last token derivation, or null when
     * this host had no usable /etc/machine-id. LocalLicenseService uses it for
     * the anti-copy gate.
     */
    public ?string $machineIdToken = null;

    /**
     * Tokens identifying this host — used both to build the license-request key
     * and to match an installed .lic. Prefers the encrypted fingerprint captured
     * by the installer (settings); falls back to a best-effort live read when no
     * fingerprint is stored (e.g. an install predating fingerprint capture).
     *
     * @return array<int, string>
     */
    public function currentHostTokens(): array
    {
        $identifiers = $this->storedFingerprint();
        if ($identifiers === []) {
            $identifiers = $this->liveIdentifiers();
        }

        return $this->tokensFromIdentifiers($identifiers);
    }

    /**
     * Capture the given identifier map as the host fingerprint (encrypted) and
     * return the derived tokens. Filters empty / placeholder values and requires
     * at least one usable identifier.
     *
     * @param  array<string, string|null>  $identifiers
     * @return array<int, string>
     *
     * @throws RuntimeException when no usable identifier was supplied or the
     *                          fingerprint could not be encrypted (svault0 key
     *                          unavailable).
     */
    public function storeFingerprint(array $identifiers): array
    {
        $clean = $this->filterIdentifiers($identifiers);
        if ($clean === []) {
            throw new RuntimeException('No usable hardware identifiers to fingerprint this host.');
        }

        $cipher = app(LicensingPassphraseService::class)->encrypt((string) json_encode($clean));
        if (! $cipher) {
            throw new RuntimeException('Could not encrypt the host fingerprint (svault0 key unavailable).');
        }

        Setting::updateOrCreate(
            ['key' => self::FINGERPRINT_SETTING_KEY],
            [
                'display_name' => 'Appliance machine fingerprint',
                'value' => $cipher,
                'type' => 'text',
                'order' => 0,
            ],
        );

        return $this->tokensFromIdentifiers($clean);
    }

    /**
     * Decrypt and return the stored host-fingerprint identifier map, or [] when
     * none is stored, it cannot be decrypted, or the DB is unavailable.
     *
     * @return array<string, string>
     */
    public function storedFingerprint(): array
    {
        try {
            $cipher = Setting::where('key', self::FINGERPRINT_SETTING_KEY)->value('value');
        } catch (Throwable $e) {
            // No DB / settings table (e.g. a bare CLI context) — degrade to live.
            return [];
        }

        if (! $cipher) {
            return [];
        }

        $plain = app(LicensingPassphraseService::class)->decrypt($cipher);
        if ($plain === '') {
            return [];
        }

        $map = json_decode($plain, true);

        return is_array($map) ? $this->filterIdentifiers($map) : [];
    }

    /**
     * Compose one independent, namespaced sha256 token per present identifier.
     * Records bindingStrength + machineIdToken as a side effect.
     *
     * @param  array<string, string>  $identifiers
     * @return array<int, string>
     */
    public function tokensFromIdentifiers(array $identifiers): array
    {
        $identifiers = $this->filterIdentifiers($identifiers);

        $this->machineIdToken = null;
        $tokens = [];

        foreach (self::IDENTIFIER_TYPES as $type) {
            if (! isset($identifiers[$type])) {
                continue;
            }
            $token = $this->hash($type.':'.$identifiers[$type]);
            if ($type === 'machine_id') {
                $this->machineIdToken = $token;
            }
            $tokens[] = $token;
        }

        $tokens = array_values(array_unique($tokens));
        $this->bindingStrength = count($tokens) > 1 ? 'strong' : 'weak';

        return $tokens;
    }

    /**
     * @deprecated Use currentHostTokens(). Returns just the machine-id token, or
     * an empty string when this host has no readable /etc/machine-id.
     */
    public function currentHostToken(): string
    {
        $machineId = $this->readMachineIdOrNull();

        return $machineId !== null ? $this->hash('machine_id:'.$machineId) : '';
    }

    /**
     * Encode a list of machine tokens into a single, copy-pasteable license
     * request key. The key is NOT secret — it carries only this host's hardware
     * fingerprint; the trust anchor remains the issuer-signed license matched to
     * the host at install time. Encoding is base64url(JSON) with a versioned prefix.
     *
     * @param  array<int, string>  $tokens
     */
    public function encode(array $tokens): string
    {
        $json = (string) json_encode(['v' => 1, 'tokens' => array_values($tokens)]);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return self::KEY_PREFIX.$b64;
    }

    /**
     * Decode + validate a license request key produced by encode(). Returns the
     * token array. Throws when the key is malformed: wrong prefix, bad base64,
     * bad JSON, unknown version, empty token list, or a token that is not a
     * "sha256:<64-hex>" string.
     *
     * @return array<int, string>
     *
     * @throws RuntimeException
     */
    public function decode(string $key): array
    {
        $key = trim($key);
        if (! str_starts_with($key, self::KEY_PREFIX)) {
            throw new RuntimeException('Unrecognised license request key.');
        }

        $b64 = substr($key, strlen(self::KEY_PREFIX));
        $decoded = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('License request key is not valid base64.');
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload) || ($payload['v'] ?? null) !== 1 || ! is_array($payload['tokens'] ?? null)) {
            throw new RuntimeException('License request key payload is malformed.');
        }

        $tokens = array_values($payload['tokens']);
        if ($tokens === []) {
            throw new RuntimeException('License request key contains no machine tokens.');
        }

        foreach ($tokens as $token) {
            if (! is_string($token) || ! preg_match('/^sha256:[0-9a-f]{64}$/', $token)) {
                throw new RuntimeException('License request key contains an invalid machine token.');
            }
        }

        return $tokens;
    }

    /**
     * Best-effort live identifiers when no fingerprint is stored: machine-id (if
     * readable) plus the DMI serial the helper can reach. Never throws — a host
     * that can only produce one identifier installs under weak binding.
     *
     * @return array<string, string>
     */
    private function liveIdentifiers(): array
    {
        $identifiers = [];

        $machineId = $this->readMachineIdOrNull();
        if ($machineId !== null) {
            $identifiers['machine_id'] = $machineId;
        }

        $boardSerial = $this->readLiveBoardSerial();
        if ($boardSerial !== null) {
            $identifiers['board_serial'] = $boardSerial;
        }

        return $identifiers;
    }

    private function readMachineIdOrNull(): ?string
    {
        $path = '/etc/machine-id';
        if (! is_readable($path)) {
            return null;
        }

        $machineId = trim((string) file_get_contents($path));

        return $machineId !== '' ? $machineId : null;
    }

    /**
     * Invoke sysadmin/machine-token-helper to read the DMI baseboard / system
     * serial. Returns null when the helper is missing, errors out, or prints
     * UNKNOWN — never throws.
     */
    private function readLiveBoardSerial(): ?string
    {
        $helper = config('appliance.machine_token_helper', base_path('sysadmin/machine-token-helper'));
        if (! is_executable($helper)) {
            Log::info("MachineTokenService: helper {$helper} not executable — weak binding");

            return null;
        }

        $result = Process::run($helper);
        if (! $result->successful()) {
            Log::warning('MachineTokenService: machine-token-helper exited '.$result->exitCode().': '.trim($result->errorOutput()));

            return null;
        }

        $output = trim($result->output());
        if ($output === '' || $output === 'UNKNOWN') {
            return null;
        }

        return $output;
    }

    /**
     * Keep only non-empty, non-placeholder identifiers of the known types.
     *
     * @param  array<string, string|null>  $identifiers
     * @return array<string, string>
     */
    private function filterIdentifiers(array $identifiers): array
    {
        $clean = [];
        foreach (self::IDENTIFIER_TYPES as $type) {
            $value = trim((string) ($identifiers[$type] ?? ''));
            if ($value !== '' && ! $this->isPlaceholder($value)) {
                $clean[$type] = $value;
            }
        }

        return $clean;
    }

    /**
     * DMI placeholder values a BIOS emits when a field is unprogrammed (mirrors
     * sysadmin/machine-token-helper's is_placeholder), plus the all-zero UUID.
     */
    private function isPlaceholder(string $value): bool
    {
        $v = strtolower((string) preg_replace('/\s+/', '', $value));

        return in_array($v, [
            'tobefilledbyo.e.m.',
            'notspecified',
            'none',
            'systemserialnumber',
            'defaultstring',
            '00000000-0000-0000-0000-000000000000',
        ], true);
    }

    private function hash(string $value): string
    {
        return 'sha256:'.hash('sha256', $value);
    }
}
