<?php

namespace App\Services\Compliance;

use App\Providers\DataTools;
use Illuminate\Support\Facades\Log;

/**
 * Builds the flat facts array a Ruleset::rules() check closure inspects.
 * Called once per analysis run (Sprint C wires the queued job); deliberately
 * has no dotfile cache of its own — the persisted ComplianceFinding rows ARE
 * the cache. Every domain is independently best-effort: a missing or
 * unreadable file/getter never throws, it just narrows the facts array.
 */
class ComplianceFactExtractor
{
    public function extract(DataTools $dtools): array
    {
        $facts = [];

        $facts['os'] = $this->safe(fn () => $this->extractOsFacts($dtools), [
            'family' => 'unknown', 'id' => null, 'version_id' => null, 'name' => null, 'pretty_name' => null,
        ]);

        $facts['ssh'] = $this->safe(fn () => $this->extractSshFacts($dtools), ['directives' => []]);

        $facts['pam'] = $this->safe(fn () => $this->extractPamFacts($dtools), []);

        $sudoers = $this->safe(fn () => $this->extractSudoersFacts($dtools), null);
        if ($sudoers !== null) {
            $facts['sudoers'] = $sudoers;
        }

        $facts['firewall'] = $this->safe(fn () => $this->normalize($dtools->getIpTablesData()), null);
        $facts['sysctl'] = $this->safe(fn () => $this->normalize($dtools->getKernelParamsData()), null);
        $facts['systemd'] = $this->safe(fn () => $this->normalize($dtools->getSystemdData()), null);

        $selinux = $this->safe(fn () => $this->extractSelinuxFacts($dtools), null);
        if ($selinux !== null) {
            $facts['selinux'] = $selinux;
        }

        $auditd = $this->safe(fn () => $this->extractAuditdFacts($dtools), null);
        if ($auditd !== null) {
            $facts['auditd'] = $auditd;
        }

        $loginDefs = $this->safe(fn () => $this->extractLoginDefsFacts($dtools), null);
        if ($loginDefs !== null) {
            $facts['login_defs'] = $loginDefs;
        }

        $facts['packages'] = $this->safe(fn () => $this->normalize($dtools->getPackagesData()), null);

        // getDockerData() already parses etc/docker/daemon.json into
        // 'daemon_config' (Sprint A), so there is no separate raw-text read
        // here — it would just duplicate that work.
        $facts['docker'] = $this->safe(fn () => $this->normalize($dtools->getDockerData()), null);

        $facts['network'] = $this->safe(fn () => $this->normalize($dtools->getNetworkData()), []);

        return $facts;
    }

    private function safe(\Closure $fn, mixed $default): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('ComplianceFactExtractor: '.$e->getMessage());

            return $default;
        }
    }

    private function extractOsFacts(DataTools $dtools): array
    {
        $raw = $this->normalize($dtools->osVersion()) ?? [];
        $id = strtolower($raw['ID'] ?? '');

        return [
            'family' => $this->resolveOsFamily($id),
            'id' => $raw['ID'] ?? null,
            'version_id' => $raw['VERSION_ID'] ?? null,
            'name' => $raw['NAME'] ?? null,
            'pretty_name' => $raw['PRETTY_NAME'] ?? null,
        ];
    }

    private function resolveOsFamily(string $id): string
    {
        return match (true) {
            in_array($id, ['rhel', 'redhatenterpriseserver', 'centos', 'almalinux', 'rocky', 'ol', 'fedora'], true) => 'rhel',
            in_array($id, ['debian', 'ubuntu'], true) => 'debian',
            in_array($id, ['sles', 'sled', 'suse', 'opensuse', 'opensuse-leap'], true) => 'suse',
            default => 'unknown',
        };
    }

    private function extractSshFacts(DataTools $dtools): array
    {
        $lines = [];

        // Include'd drop-ins are conventionally read before the rest of
        // sshd_config and take priority under first-wins semantics, so parse
        // them first.
        foreach ($this->safeListDir($dtools, 'etc/ssh/sshd_config.d') as $entry) {
            if (! str_ends_with($entry, '.conf')) {
                continue;
            }

            $dropinLines = $this->safeReadFile($dtools, "etc/ssh/sshd_config.d/{$entry}");
            if ($dropinLines) {
                $lines = array_merge($lines, $dropinLines);
            }
        }

        $main = $this->safeReadFile($dtools, 'etc/ssh/sshd_config');
        if ($main) {
            $lines = array_merge($lines, $main);
        }

        return ['directives' => $this->parseSpaceKeyValue($lines)];
    }

    private function extractPamFacts(DataTools $dtools): array
    {
        $files = [
            'system_auth' => 'etc/pam.d/system-auth',
            'password_auth' => 'etc/pam.d/password-auth',
            'common_auth' => 'etc/pam.d/common-auth',
            'common_password' => 'etc/pam.d/common-password',
            'sudo' => 'etc/pam.d/sudo',
            'sshd' => 'etc/pam.d/sshd',
        ];

        $pam = [];
        foreach ($files as $key => $path) {
            $lines = $this->safeReadFile($dtools, $path);
            if ($lines === null) {
                continue;
            }

            $pam[$key] = $this->filterMeaningfulLines($lines);
        }

        return $pam;
    }

    private function extractSudoersFacts(DataTools $dtools): ?array
    {
        $lines = $this->safeReadFile($dtools, 'etc/sudoers');
        if ($lines === null) {
            return null;
        }

        return ['raw_lines' => $this->filterMeaningfulLines($lines)];
    }

    private function extractSelinuxFacts(DataTools $dtools): ?array
    {
        $lines = $this->safeReadFile($dtools, 'etc/selinux/config');
        if ($lines === null) {
            return null;
        }

        $directives = $this->parseEqualsKeyValue($lines);

        return [
            'config_mode' => $directives['selinux'] ?? null,
            'type' => $directives['selinuxtype'] ?? null,
        ];
    }

    // Combines etc/audit/auditd.conf (daemon tunables) with etc/audit/audit.rules
    // (file watch rules, e.g. "-w /usr/bin/dockerd -p x") — the latter is what
    // DockerBenchRuleset cross-checks for CIS Docker Benchmark section 1.1's
    // "auditing is configured for docker files" controls. Either source alone
    // is enough to produce facts; both missing means the whole key is absent.
    private function extractAuditdFacts(DataTools $dtools): ?array
    {
        $confLines = $this->safeReadFile($dtools, 'etc/audit/auditd.conf');
        $rulesLines = $this->safeReadFile($dtools, 'etc/audit/audit.rules');

        if ($confLines === null && $rulesLines === null) {
            return null;
        }

        $facts = [];
        if ($confLines !== null) {
            $facts['config'] = $this->parseEqualsKeyValue($confLines);
        }
        if ($rulesLines !== null) {
            $facts['watched_paths'] = $this->extractAuditWatchedPaths($rulesLines);
        }

        return $facts;
    }

    private function extractAuditWatchedPaths(array $lines): array
    {
        $paths = [];
        foreach ($lines as $line) {
            if (preg_match('/^-w\s+(\S+)/', trim($line), $m)) {
                $paths[] = $m[1];
            }
        }

        return array_values(array_unique($paths));
    }

    private function extractLoginDefsFacts(DataTools $dtools): ?array
    {
        $lines = $this->safeReadFile($dtools, 'etc/login.defs');
        if ($lines === null) {
            return null;
        }

        return ['directives' => $this->parseSpaceKeyValue($lines)];
    }

    private function safeReadFile(DataTools $dtools, string $path): ?array
    {
        try {
            return $dtools->readFileContents($path);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeListDir(DataTools $dtools, string $path): array
    {
        try {
            return $dtools->listDirEntries($path);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function filterMeaningfulLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $out[] = $trimmed;
        }

        return array_values($out);
    }

    // Standard sshd_config-style "Key Value" parsing: lowercase directive
    // names, first non-comment non-blank value wins.
    private function parseSpaceKeyValue(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! preg_match('/^(\S+)\s+(.*)$/', $trimmed, $m)) {
                continue;
            }

            $key = strtolower($m[1]);
            if (array_key_exists($key, $out)) {
                continue;
            }

            $out[$key] = trim($m[2]);
        }

        return $out;
    }

    // KEY=value / key = value parsing (selinux/config, auditd.conf): first
    // non-comment non-blank value wins, key lowercased.
    private function parseEqualsKeyValue(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = strtolower(trim($key));
            if (array_key_exists($key, $out)) {
                continue;
            }

            $out[$key] = trim($value);
        }

        return $out;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalize($v);
            }
        }

        return $value;
    }
}
