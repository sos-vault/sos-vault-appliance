<?php

namespace App\Services\Compliance\Rulesets\Concerns;

// Shared read-only accessors over the ComplianceFactExtractor output shape,
// used by every ruleset's check closures. Keeps each ruleset file focused on
// the actual compliance logic instead of re-deriving lookups from raw facts.
trait InspectsFacts
{
    protected static function sshDirective(array $facts, string $name): ?string
    {
        return $facts['ssh']['directives'][strtolower($name)] ?? null;
    }

    protected static function sysctlValue(array $facts, string $name): ?string
    {
        foreach ($facts['sysctl'] ?? [] as $entry) {
            if (($entry['Name'] ?? null) === $name) {
                return $entry['Value'] ?? null;
            }
        }

        return null;
    }

    protected static function systemdUnit(array $facts, string $unit): ?array
    {
        foreach ($facts['systemd']['systemd'] ?? [] as $entry) {
            if (($entry['unit'] ?? null) === $unit) {
                return $entry;
            }
        }

        return null;
    }

    protected static function unitActive(array $facts, string $unit): bool
    {
        $entry = static::systemdUnit($facts, $unit);

        return $entry !== null && strtolower($entry['active'] ?? '') === 'active';
    }

    protected static function unitEnabled(array $facts, string $unit): bool
    {
        $entry = static::systemdUnit($facts, $unit);

        return $entry !== null && str_contains(strtolower($entry['loaded'] ?? ''), 'loaded');
    }

    protected static function packageInstalled(array $facts, string $needle): bool
    {
        foreach ($facts['packages'] ?? [] as $pkg) {
            $name = strtolower($pkg['Name'] ?? '');
            if ($name !== '' && str_starts_with($name, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    protected static function pamLines(array $facts, string $file): array
    {
        return $facts['pam'][$file] ?? [];
    }

    protected static function pamContains(array $facts, string $file, string $needle): bool
    {
        foreach (static::pamLines($facts, $file) as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected static function pamModulePresent(array $facts, array $files, string $module): bool
    {
        foreach ($files as $file) {
            if (static::pamContains($facts, $file, $module)) {
                return true;
            }
        }

        return false;
    }

    protected static function pamModuleArgValue(array $facts, array $files, string $module, string $arg): ?string
    {
        foreach ($files as $file) {
            foreach (static::pamLines($facts, $file) as $line) {
                if (! str_contains($line, $module)) {
                    continue;
                }

                if (preg_match('/\b'.preg_quote($arg, '/').'=(\S+)/', $line, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    protected static function sudoersLines(array $facts): array
    {
        return $facts['sudoers']['raw_lines'] ?? [];
    }

    protected static function sudoersContains(array $facts, string $needle): bool
    {
        foreach (static::sudoersLines($facts) as $line) {
            if (str_contains(strtolower($line), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    protected static function auditdValue(array $facts, string $key): ?string
    {
        return $facts['auditd']['config'][strtolower($key)] ?? null;
    }

    protected static function auditdWatchesPath(array $facts, string $path): bool
    {
        return in_array($path, $facts['auditd']['watched_paths'] ?? [], true);
    }

    protected static function dockerDaemonConfig(array $facts, string $key): mixed
    {
        return $facts['docker']['daemon_config'][$key] ?? null;
    }

    protected static function loginDefsValue(array $facts, string $key): ?string
    {
        return $facts['login_defs']['directives'][strtolower($key)] ?? null;
    }

    protected static function firewallChain(array $facts, string $chain): ?array
    {
        return $facts['firewall'][$chain] ?? null;
    }

    protected static function firewallPolicyIsDropOrReject(array $facts, string $chain): bool
    {
        $entry = static::firewallChain($facts, $chain);
        if (! $entry) {
            return false;
        }

        $policy = strtoupper($entry['policy'] ?? '');

        return str_contains($policy, 'DROP') || str_contains($policy, 'REJECT');
    }

    protected static function listenersOnPort(array $facts, int $port): array
    {
        $matches = [];

        foreach ($facts['network'] ?? [] as $conn) {
            if (! is_array($conn)) {
                continue;
            }

            $proto = strtolower($conn['Proto'] ?? '');
            if (! str_starts_with($proto, 'tcp') && ! str_starts_with($proto, 'udp')) {
                continue;
            }

            if (str_starts_with($proto, 'tcp') && strtoupper($conn['State'] ?? '') !== 'LISTEN') {
                continue;
            }

            $local = $conn['Local_Address'] ?? '';
            $pos = strrpos($local, ':');
            if ($pos === false) {
                continue;
            }

            $host = substr($local, 0, $pos);
            $portPart = substr($local, $pos + 1);
            if (! is_numeric($portPart) || (int) $portPart !== $port) {
                continue;
            }

            if (in_array($host, ['127.0.0.1', '::1', 'localhost'], true)) {
                continue;
            }

            $matches[] = ['proto' => $proto, 'local_address' => $local];
        }

        return $matches;
    }

    protected static function toInt(?string $value): ?int
    {
        if ($value === null || ! is_numeric(trim($value))) {
            return null;
        }

        return (int) trim($value);
    }
}
