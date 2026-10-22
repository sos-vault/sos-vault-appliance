<?php

namespace App\Services;

use Wave\Setting;

/**
 * Appliance host / port configuration.
 *
 * The appliance is served over HTTPS on a host:port the operator controls.
 * That value is environment-bound (it must reach nginx via docker-compose and
 * the framework via APP_URL), so unlike most settings it is mirrored OUT of the
 * settings table into two infrastructure files:
 *   - .env              → APP_URL=https://<host>:<port>
 *   - docker-compose.yml → the nginx host-port mapped to container :443
 *
 * Both files live in the bind-mounted repo root (/var/www/site) so the app
 * container can rewrite them; the operator then restarts the stack for the
 * change to take effect. File paths are injectable so tests never touch the
 * real .env / docker-compose.yml.
 */
class ApplianceNetworkSettings
{
    public const HOST_KEY = 'appliance.host';

    public const PORT_KEY = 'appliance.port';

    public const DEFAULT_PORT = 2002;

    /**
     * Hostname / IPv4 charset. Deliberately excludes newlines and shell/env
     * metacharacters so the value is safe to interpolate into the APP_URL line
     * written to .env — a host containing a newline would otherwise inject
     * arbitrary environment lines (APP_DEBUG, secret overrides, …).
     */
    public static function isValidHost(string $host): bool
    {
        $host = trim($host);

        return $host !== '' && strlen($host) <= 253 && preg_match('/^[A-Za-z0-9.\-]+$/', $host) === 1;
    }

    private string $envPath;

    private string $composePath;

    public function __construct(?string $envPath = null, ?string $composePath = null)
    {
        $this->envPath = $envPath ?? base_path('.env');
        $this->composePath = $composePath ?? base_path('docker-compose.yml');
    }

    /**
     * The underlying OS hostname, used as the default when nothing is saved.
     */
    public static function osHostname(): string
    {
        $host = gethostname();

        return ($host !== false && $host !== '') ? $host : 'localhost';
    }

    /**
     * Currently configured host — saved value, else the OS hostname.
     */
    public static function currentHost(): string
    {
        $value = trim((string) Setting::where('key', self::HOST_KEY)->value('value'));

        return $value !== '' ? $value : self::osHostname();
    }

    /**
     * Currently configured HTTPS port — saved value, else the default (2002).
     */
    public static function currentPort(): int
    {
        $value = (int) Setting::where('key', self::PORT_KEY)->value('value');

        return $value > 0 ? $value : self::DEFAULT_PORT;
    }

    /**
     * Persist host/port to the settings table and mirror them into .env
     * (APP_URL) and docker-compose.yml (nginx :443 host-port mapping).
     *
     * @return string[] absolute paths of the infra files actually rewritten
     */
    public function apply(string $host, int $port): array
    {
        $host = trim($host);

        // Authoritative guard: never write an unvalidated host into .env.
        if (! self::isValidHost($host)) {
            throw new \InvalidArgumentException('Invalid appliance host: use a hostname or IPv4 address (letters, digits, dots, hyphens).');
        }

        Setting::updateOrCreate(
            ['key' => self::HOST_KEY],
            ['display_name' => self::HOST_KEY, 'value' => $host, 'type' => 'text', 'order' => 0]
        );
        Setting::updateOrCreate(
            ['key' => self::PORT_KEY],
            ['display_name' => self::PORT_KEY, 'value' => (string) $port, 'type' => 'text', 'order' => 0]
        );

        $updated = [];
        if ($this->updateEnv($host, $port)) {
            $updated[] = $this->envPath;
        }
        if ($this->updateCompose($port)) {
            $updated[] = $this->composePath;
        }

        return $updated;
    }

    /**
     * Rewrite (or append) the APP_URL line in .env.
     */
    private function updateEnv(string $host, int $port): bool
    {
        if (! is_file($this->envPath) || ! is_writable($this->envPath)) {
            return false;
        }

        $contents = (string) file_get_contents($this->envPath);
        $line = sprintf('APP_URL=https://%s:%d', $host, $port);

        if (preg_match('/^APP_URL=.*$/m', $contents) === 1) {
            $contents = preg_replace('/^APP_URL=.*$/m', $line, $contents, 1);
        } else {
            $contents = rtrim($contents, "\n")."\n".$line."\n";
        }

        return file_put_contents($this->envPath, $contents) !== false;
    }

    /**
     * Rewrite the nginx host port mapped to the container's HTTPS port (:443).
     * Leaves the plain-HTTP (:80) mapping untouched.
     */
    private function updateCompose(int $port): bool
    {
        if (! is_file($this->composePath) || ! is_writable($this->composePath)) {
            return false;
        }

        $contents = (string) file_get_contents($this->composePath);
        $count = 0;
        $new = preg_replace('/(-\s*)\d+:443\b/', '${1}'.$port.':443', $contents, 1, $count);

        if ($count < 1 || $new === null) {
            return false;
        }

        return file_put_contents($this->composePath, $new) !== false;
    }
}
