<?php

namespace App\Services\Compliance\Rulesets;

use App\Services\Compliance\Rulesets\Concerns\InspectsFacts;

/**
 * "Is this system exploitable from the network / by any local user" checks —
 * distinct framing from CIS/STIG hardening: focuses on exposure surface
 * (public listeners, passwordless privilege escalation, legacy/plaintext
 * services) rather than general baseline configuration.
 */
class ExposureRuleset
{
    use InspectsFacts;

    private const SENSITIVE_PORTS = [22, 3306, 5432, 6379, 27017, 9200];

    private const RISKY_PACKAGES = [
        'telnet-server' => 'CRITICAL',
        'rsh-server' => 'CRITICAL',
        'tftp-server' => 'HIGH',
        'xinetd' => 'MEDIUM',
        'vsftpd' => 'MEDIUM',
        'ftp' => 'MEDIUM',
        'nfs-utils' => 'MEDIUM',
        'telnet' => 'LOW',
    ];

    private const EXPOSED_SERVICE_UNITS = [
        'avahi-daemon.service' => 'LOW',
        'cups.service' => 'LOW',
        'rpcbind.service' => 'MEDIUM',
        'nfs-server.service' => 'MEDIUM',
        'telnet.socket' => 'CRITICAL',
        'rsh.socket' => 'CRITICAL',
        'rexec.socket' => 'CRITICAL',
        'ypbind.service' => 'MEDIUM',
        'vsftpd.service' => 'MEDIUM',
        'xinetd.service' => 'MEDIUM',
    ];

    public static function rules(array $context): array
    {
        $rules = array_merge(
            self::networkRules(),
            self::softwareRules(),
            self::osEolRules(),
            self::identityRules(),
            self::systemdRules(),
        );

        return array_map(fn (array $rule) => $rule + ['ruleset' => 'exposure'], $rules);
    }

    private static function boolResult(bool $pass, array $evidence, ?string $path = null): array
    {
        return ['status' => $pass ? 'pass' : 'fail', 'evidence' => $evidence, 'matched_path' => $path];
    }

    private static function networkRules(): array
    {
        $rules = [
            [
                'id' => 'exposure-firewall-input-default-accept',
                'category' => 'Network Exposure',
                'title' => 'iptables INPUT chain must not default to ACCEPT',
                'description' => 'Verifies the default policy on the INPUT chain is not ACCEPT.',
                'severity' => 'CRITICAL',
                'remediation' => 'Set the default policy on the INPUT chain to DROP (iptables -P INPUT DROP).',
                'reference_url' => null,
                'check' => function (array $f) {
                    $chain = self::firewallChain($f, 'INPUT');
                    $policy = strtoupper($chain['policy'] ?? '');

                    return self::boolResult($chain !== null && ! str_contains($policy, 'ACCEPT'), ['policy' => $chain['policy'] ?? null]);
                },
            ],
            [
                'id' => 'exposure-firewall-forward-default-accept',
                'category' => 'Network Exposure',
                'title' => 'iptables FORWARD chain must not default to ACCEPT',
                'description' => 'Verifies the default policy on the FORWARD chain is not ACCEPT.',
                'severity' => 'HIGH',
                'remediation' => 'Set the default policy on the FORWARD chain to DROP (iptables -P FORWARD DROP).',
                'reference_url' => null,
                'check' => function (array $f) {
                    $chain = self::firewallChain($f, 'FORWARD');
                    $policy = strtoupper($chain['policy'] ?? '');

                    return self::boolResult($chain !== null && ! str_contains($policy, 'ACCEPT'), ['policy' => $chain['policy'] ?? null]);
                },
            ],
            [
                'id' => 'exposure-firewall-output-default-accept',
                'category' => 'Network Exposure',
                'title' => 'iptables OUTPUT chain should enforce egress filtering',
                'description' => 'Verifies the default policy on the OUTPUT chain is not a bare ACCEPT with no rules.',
                'severity' => 'LOW',
                'remediation' => 'Consider a default-DROP OUTPUT policy with explicit egress allow rules.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $chain = self::firewallChain($f, 'OUTPUT');
                    $policy = strtoupper($chain['policy'] ?? '');
                    $hasRules = ! empty($chain['data'] ?? []);

                    return self::boolResult($chain !== null && (! str_contains($policy, 'ACCEPT') || $hasRules), ['policy' => $chain['policy'] ?? null, 'rule_count' => count($chain['data'] ?? [])]);
                },
            ],
            [
                'id' => 'exposure-firewall-no-rules',
                'category' => 'Network Exposure',
                'title' => 'A firewall must be present',
                'description' => 'Verifies iptables data was captured at all (no capture means no local firewall, or it could not be evidenced).',
                'severity' => 'HIGH',
                'remediation' => 'Install and enable a host firewall (firewalld/iptables/nftables).',
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(! empty($f['firewall']), ['firewall_present' => ! empty($f['firewall'])]),
            ],
        ];

        foreach (self::SENSITIVE_PORTS as $port) {
            $rules[] = [
                'id' => "exposure-port-{$port}-public",
                'category' => 'Network Exposure',
                'title' => "Port {$port} must not be bound to a public interface",
                'description' => "Verifies no listener on port {$port} is bound outside loopback.",
                'severity' => 'HIGH',
                'remediation' => "Bind the service on port {$port} to 127.0.0.1/::1, or firewall it off from untrusted networks.",
                'reference_url' => null,
                'check' => function (array $f) use ($port) {
                    $matches = self::listenersOnPort($f, $port);

                    return self::boolResult($matches === [], ['port' => $port, 'listeners' => $matches]);
                },
            ];
        }

        return $rules;
    }

    private static function softwareRules(): array
    {
        $rules = [];
        foreach (self::RISKY_PACKAGES as $package => $severity) {
            $rules[] = [
                'id' => "exposure-package-{$package}",
                'category' => 'Software Exposure',
                'title' => "Package '{$package}' should not be installed",
                'description' => "Verifies the '{$package}' package is not present.",
                'severity' => $severity,
                'remediation' => "Remove the '{$package}' package if it is not required.",
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(! self::packageInstalled($f, $package), ['package' => $package]),
            ];
        }

        return $rules;
    }

    private static function osEolRules(): array
    {
        return [
            [
                'id' => 'exposure-os-eol',
                'category' => 'Software Exposure',
                'title' => 'Operating system version should not be past its supported lifecycle',
                'description' => 'Heuristic EOL check derived from the OS family/major version captured in etc/os-release.',
                'severity' => 'HIGH',
                'remediation' => 'Upgrade to a currently-supported OS release.',
                'reference_url' => null,
                'applicable' => fn (array $f) => ($f['os']['family'] ?? 'unknown') !== 'unknown' && ! empty($f['os']['version_id']),
                'check' => function (array $f) {
                    $family = $f['os']['family'];
                    $versionId = (string) $f['os']['version_id'];
                    $major = (int) explode('.', $versionId)[0];

                    $eol = match ($family) {
                        'rhel' => $major < 8,
                        'debian' => $major < 20,
                        'suse' => $major < 15,
                        default => false,
                    };

                    return self::boolResult(! $eol, ['family' => $family, 'version_id' => $versionId]);
                },
            ],
        ];
    }

    private static function identityRules(): array
    {
        return [
            [
                'id' => 'exposure-sudoers-nopasswd-all',
                'category' => 'Identity Exposure',
                'title' => 'sudoers must not grant blanket NOPASSWD:ALL',
                'description' => 'Verifies etc/sudoers does not contain a blanket NOPASSWD:ALL entry.',
                'severity' => 'CRITICAL',
                'remediation' => 'Remove any "NOPASSWD: ALL" entries from /etc/sudoers.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $found = false;
                    foreach (self::sudoersLines($f) as $line) {
                        if (preg_match('/NOPASSWD\s*:\s*ALL/i', $line)) {
                            $found = true;
                            break;
                        }
                    }

                    return self::boolResult(! $found, ['blanket_nopasswd' => $found], 'etc/sudoers');
                },
            ],
            [
                'id' => 'exposure-sudoers-nopasswd-any',
                'category' => 'Identity Exposure',
                'title' => 'sudoers should not grant any passwordless privilege escalation',
                'description' => 'Verifies etc/sudoers does not contain any NOPASSWD entry.',
                'severity' => 'HIGH',
                'remediation' => 'Remove NOPASSWD entries from /etc/sudoers, or scope them tightly to specific low-risk commands.',
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(! self::sudoersContains($f, 'NOPASSWD'), ['directive' => 'NOPASSWD'], 'etc/sudoers'),
            ],
            [
                'id' => 'exposure-pam-nullok-system-auth',
                'category' => 'Identity Exposure',
                'title' => 'PAM must not allow empty passwords (system-auth)',
                'description' => "Verifies 'nullok' is not present in etc/pam.d/system-auth.",
                'severity' => 'CRITICAL',
                'remediation' => 'Remove the "nullok" argument from pam_unix.so in /etc/pam.d/system-auth.',
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(! self::pamContains($f, 'system_auth', 'nullok'), ['directive' => 'nullok'], 'etc/pam.d/system-auth'),
            ],
            [
                'id' => 'exposure-pam-nullok-password-auth',
                'category' => 'Identity Exposure',
                'title' => 'PAM must not allow empty passwords (password-auth)',
                'description' => "Verifies 'nullok' is not present in etc/pam.d/password-auth.",
                'severity' => 'CRITICAL',
                'remediation' => 'Remove the "nullok" argument from pam_unix.so in /etc/pam.d/password-auth.',
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(! self::pamContains($f, 'password_auth', 'nullok'), ['directive' => 'nullok'], 'etc/pam.d/password-auth'),
            ],
            [
                'id' => 'exposure-ssh-permit-root-login-yes',
                'category' => 'Identity Exposure',
                'title' => 'SSH must not permit direct root login',
                'description' => "Verifies 'PermitRootLogin' is not 'yes' or 'without-password'.",
                'severity' => 'CRITICAL',
                'remediation' => 'Set "PermitRootLogin no" in sshd_config.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $value = strtolower((string) self::sshDirective($f, 'permitrootlogin'));

                    return self::boolResult(! in_array($value, ['yes', 'without-password', 'prohibit-password'], true), ['directive' => 'PermitRootLogin', 'found' => $value], 'etc/ssh/sshd_config');
                },
            ],
            [
                'id' => 'exposure-ssh-permit-empty-passwords',
                'category' => 'Identity Exposure',
                'title' => 'SSH must not permit empty passwords',
                'description' => "Verifies 'PermitEmptyPasswords' is not 'yes'.",
                'severity' => 'CRITICAL',
                'remediation' => 'Set "PermitEmptyPasswords no" in sshd_config.',
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(strtolower((string) self::sshDirective($f, 'permitemptypasswords')) !== 'yes', ['directive' => 'PermitEmptyPasswords'], 'etc/ssh/sshd_config'),
            ],
            [
                'id' => 'exposure-ssh-hostbased-auth',
                'category' => 'Identity Exposure',
                'title' => 'SSH host-based authentication should be disabled',
                'description' => "Verifies 'HostbasedAuthentication' is not 'yes'.",
                'severity' => 'MEDIUM',
                'remediation' => 'Set "HostbasedAuthentication no" in sshd_config.',
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(strtolower((string) self::sshDirective($f, 'hostbasedauthentication')) !== 'yes', ['directive' => 'HostbasedAuthentication'], 'etc/ssh/sshd_config'),
            ],
            [
                'id' => 'exposure-ssh-x11forwarding',
                'category' => 'Identity Exposure',
                'title' => 'SSH X11 forwarding should be disabled',
                'description' => "Verifies 'X11Forwarding' is not 'yes'.",
                'severity' => 'LOW',
                'remediation' => 'Set "X11Forwarding no" in sshd_config.',
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(strtolower((string) self::sshDirective($f, 'x11forwarding')) !== 'yes', ['directive' => 'X11Forwarding'], 'etc/ssh/sshd_config'),
            ],
            [
                'id' => 'exposure-ssh-no-user-restriction',
                'category' => 'Identity Exposure',
                'title' => 'SSH access should be restricted to specific users/groups',
                'description' => 'Verifies at least one of AllowUsers/AllowGroups/DenyUsers/DenyGroups is set.',
                'severity' => 'LOW',
                'remediation' => 'Set AllowUsers/AllowGroups in sshd_config to scope who may log in over SSH.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $directives = $f['ssh']['directives'] ?? [];
                    $present = array_intersect_key($directives, array_flip(['allowusers', 'allowgroups', 'denyusers', 'denygroups']));

                    return self::boolResult($present !== [], ['restricting_directives' => array_keys($present)], 'etc/ssh/sshd_config');
                },
            ],
            [
                'id' => 'exposure-pam-permit-referenced',
                'category' => 'Identity Exposure',
                'title' => 'PAM must not reference pam_permit.so',
                'description' => 'Verifies pam_permit.so (unconditional success) is not referenced by any captured PAM file.',
                'severity' => 'CRITICAL',
                'remediation' => 'Remove any pam_permit.so lines from PAM configuration.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $found = self::pamModulePresent($f, ['system_auth', 'password_auth', 'common_auth', 'common_password', 'sudo', 'sshd'], 'pam_permit.so');

                    return self::boolResult(! $found, ['module' => 'pam_permit.so']);
                },
            ],
            [
                'id' => 'exposure-pam-rhosts-referenced',
                'category' => 'Identity Exposure',
                'title' => 'PAM must not reference pam_rhosts.so',
                'description' => 'Verifies pam_rhosts.so (legacy host-trust authentication) is not referenced by any captured PAM file.',
                'severity' => 'HIGH',
                'remediation' => 'Remove any pam_rhosts.so / pam_rhosts_auth.so lines from PAM configuration.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $found = self::pamModulePresent($f, ['system_auth', 'password_auth', 'common_auth', 'common_password', 'sudo', 'sshd'], 'pam_rhosts');

                    return self::boolResult(! $found, ['module' => 'pam_rhosts.so']);
                },
            ],
        ];
    }

    private static function systemdRules(): array
    {
        $rules = [];
        foreach (self::EXPOSED_SERVICE_UNITS as $unit => $severity) {
            $rules[] = [
                'id' => 'exposure-service-'.str_replace('.', '-', $unit),
                'category' => 'Service Exposure',
                'title' => "{$unit} should not be active",
                'description' => "Verifies {$unit} is not active.",
                'severity' => $severity,
                'remediation' => "Disable and stop {$unit} unless required (systemctl disable --now {$unit}).",
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(! self::unitActive($f, $unit), ['unit' => $unit, 'entry' => self::systemdUnit($f, $unit)]),
            ];
        }

        return $rules;
    }
}
