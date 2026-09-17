<?php

namespace App\Services\Compliance\Rulesets;

use App\Services\Compliance\Rulesets\Concerns\InspectsFacts;

/**
 * CIS-flavoured baseline hardening checks against the static facts captured
 * from a sosreport (no live host to query, no `sshd -T` effective-config
 * resolution — every directive check reads the file as written). A directive
 * that CIS wants explicitly set but which is entirely absent from the
 * captured file is treated as a fail: we cannot verify the compiled-in
 * default from a static capture, so "not configured" is flagged for review
 * rather than assumed safe.
 */
class CisRuleset
{
    use InspectsFacts;

    private const WEAK_CIPHERS = ['3des-cbc', 'arcfour', 'blowfish-cbc', 'cast128-cbc', 'aes128-cbc', 'aes192-cbc', 'aes256-cbc'];

    private const WEAK_MACS = ['hmac-md5', 'hmac-sha1', 'hmac-md5-96', 'hmac-sha1-96', 'umac-64'];

    public static function rules(array $context): array
    {
        $family = $context['os_family'] ?? 'unknown';

        $rules = [];

        foreach (self::sshRules() as $rule) {
            $rules[] = $rule;
        }

        foreach (self::pamRules() as $rule) {
            $rules[] = $rule;
        }

        foreach (self::sudoersRules() as $rule) {
            $rules[] = $rule;
        }

        foreach (self::firewallRules() as $rule) {
            $rules[] = $rule;
        }

        foreach (self::sysctlRules() as $rule) {
            $rules[] = $rule;
        }

        $rules[] = self::selinuxRule();
        $rules[] = self::auditdEnabledRule();
        $rules[] = self::auditdActiveRule();

        foreach (self::unnecessaryServiceRules($family) as $rule) {
            $rules[] = $rule;
        }

        return array_map(fn (array $rule) => $rule + ['ruleset' => 'cis'], $rules);
    }

    private static function sshDirectiveRule(string $id, string $directive, array $passValues, string $title, string $severity, string $remediation): array
    {
        return [
            'id' => $id,
            'category' => 'SSH Hardening',
            'title' => $title,
            'description' => "Verifies the '{$directive}' directive in etc/ssh/sshd_config.",
            'severity' => $severity,
            'remediation' => $remediation,
            'reference_url' => null,
            'check' => function (array $facts) use ($directive, $passValues) {
                $value = self::sshDirective($facts, $directive);
                $status = $value !== null && in_array(strtolower(trim($value)), array_map('strtolower', $passValues), true)
                    ? 'pass'
                    : 'fail';

                return [
                    'status' => $status,
                    'evidence' => ['directive' => $directive, 'found' => $value],
                    'matched_path' => 'etc/ssh/sshd_config',
                ];
            },
        ];
    }

    private static function sshRules(): array
    {
        return [
            self::sshDirectiveRule('cis-ssh-protocol', 'protocol', ['2'], 'SSH Protocol must be 2', 'HIGH', "Remove any 'Protocol 1' directive; SSHv2 is the only supported protocol."),
            self::sshDirectiveRule('cis-ssh-loglevel', 'loglevel', ['info', 'verbose'], 'SSH LogLevel must be INFO or VERBOSE', 'LOW', 'Set "LogLevel INFO" in sshd_config.'),
            self::sshDirectiveRule('cis-ssh-x11forwarding', 'x11forwarding', ['no'], 'SSH X11Forwarding must be disabled', 'MEDIUM', 'Set "X11Forwarding no" in sshd_config.'),
            self::sshDirectiveRule('cis-ssh-permitrootlogin', 'permitrootlogin', ['no'], 'SSH root login must be disabled', 'HIGH', 'Set "PermitRootLogin no" in sshd_config.'),
            self::sshDirectiveRule('cis-ssh-permitemptypasswords', 'permitemptypasswords', ['no'], 'SSH empty passwords must be disabled', 'CRITICAL', 'Set "PermitEmptyPasswords no" in sshd_config.'),
            self::sshDirectiveRule('cis-ssh-ignorerhosts', 'ignorerhosts', ['yes'], 'SSH must ignore rhosts', 'MEDIUM', 'Set "IgnoreRhosts yes" in sshd_config.'),
            self::sshDirectiveRule('cis-ssh-hostbasedauthentication', 'hostbasedauthentication', ['no'], 'SSH hostbased authentication must be disabled', 'MEDIUM', 'Set "HostbasedAuthentication no" in sshd_config.'),
            self::sshDirectiveRule('cis-ssh-permituserenvironment', 'permituserenvironment', ['no'], 'SSH PermitUserEnvironment must be disabled', 'MEDIUM', 'Set "PermitUserEnvironment no" in sshd_config.'),
            self::sshDirectiveRule('cis-ssh-banner', 'banner', ['/etc/issue.net', '/etc/issue'], 'SSH must display a warning banner', 'LOW', 'Set "Banner /etc/issue.net" in sshd_config.'),
            [
                'id' => 'cis-ssh-maxauthtries',
                'category' => 'SSH Hardening',
                'title' => 'SSH MaxAuthTries must be 4 or less',
                'description' => "Verifies the 'MaxAuthTries' directive in etc/ssh/sshd_config.",
                'severity' => 'MEDIUM',
                'remediation' => 'Set "MaxAuthTries 4" (or lower) in sshd_config.',
                'reference_url' => null,
                'check' => function (array $facts) {
                    $value = self::toInt(self::sshDirective($facts, 'maxauthtries'));
                    $status = $value !== null && $value <= 4 ? 'pass' : 'fail';

                    return ['status' => $status, 'evidence' => ['directive' => 'MaxAuthTries', 'found' => $value], 'matched_path' => 'etc/ssh/sshd_config'];
                },
            ],
            [
                'id' => 'cis-ssh-clientaliveinterval',
                'category' => 'SSH Hardening',
                'title' => 'SSH ClientAliveInterval must be set',
                'description' => "Verifies the 'ClientAliveInterval' directive in etc/ssh/sshd_config.",
                'severity' => 'LOW',
                'remediation' => 'Set "ClientAliveInterval 300" (or another positive value) in sshd_config.',
                'reference_url' => null,
                'check' => function (array $facts) {
                    $value = self::toInt(self::sshDirective($facts, 'clientaliveinterval'));
                    $status = $value !== null && $value > 0 ? 'pass' : 'fail';

                    return ['status' => $status, 'evidence' => ['directive' => 'ClientAliveInterval', 'found' => $value], 'matched_path' => 'etc/ssh/sshd_config'];
                },
            ],
            [
                'id' => 'cis-ssh-logingracetime',
                'category' => 'SSH Hardening',
                'title' => 'SSH LoginGraceTime must be 60 seconds or less',
                'description' => "Verifies the 'LoginGraceTime' directive in etc/ssh/sshd_config.",
                'severity' => 'LOW',
                'remediation' => 'Set "LoginGraceTime 60" (or lower) in sshd_config.',
                'reference_url' => null,
                'check' => function (array $facts) {
                    $value = self::toInt(self::sshDirective($facts, 'logingracetime'));
                    $status = $value !== null && $value > 0 && $value <= 60 ? 'pass' : 'fail';

                    return ['status' => $status, 'evidence' => ['directive' => 'LoginGraceTime', 'found' => $value], 'matched_path' => 'etc/ssh/sshd_config'];
                },
            ],
            self::sshDirectiveRule('cis-ssh-allowtcpforwarding', 'allowtcpforwarding', ['no'], 'SSH AllowTcpForwarding must be disabled', 'LOW', 'Set "AllowTcpForwarding no" in sshd_config.'),
            [
                'id' => 'cis-ssh-weak-ciphers',
                'category' => 'SSH Hardening',
                'title' => 'SSH must not offer weak ciphers',
                'description' => "Verifies the 'Ciphers' directive in etc/ssh/sshd_config excludes weak algorithms.",
                'severity' => 'HIGH',
                'remediation' => 'Remove weak ciphers (3des-cbc, arcfour*, *-cbc) from the Ciphers directive.',
                'reference_url' => null,
                'check' => function (array $facts) {
                    $value = self::sshDirective($facts, 'ciphers');
                    $weak = [];
                    if ($value !== null) {
                        foreach (self::WEAK_CIPHERS as $cipher) {
                            if (str_contains(strtolower($value), $cipher)) {
                                $weak[] = $cipher;
                            }
                        }
                    }

                    return ['status' => $weak === [] ? 'pass' : 'fail', 'evidence' => ['directive' => 'Ciphers', 'found' => $value, 'weak' => $weak], 'matched_path' => 'etc/ssh/sshd_config'];
                },
            ],
            [
                'id' => 'cis-ssh-weak-macs',
                'category' => 'SSH Hardening',
                'title' => 'SSH must not offer weak MACs',
                'description' => "Verifies the 'MACs' directive in etc/ssh/sshd_config excludes weak algorithms.",
                'severity' => 'HIGH',
                'remediation' => 'Remove weak MACs (hmac-md5*, hmac-sha1*, umac-64) from the MACs directive.',
                'reference_url' => null,
                'check' => function (array $facts) {
                    $value = self::sshDirective($facts, 'macs');
                    $weak = [];
                    if ($value !== null) {
                        foreach (self::WEAK_MACS as $mac) {
                            if (str_contains(strtolower($value), $mac)) {
                                $weak[] = $mac;
                            }
                        }
                    }

                    return ['status' => $weak === [] ? 'pass' : 'fail', 'evidence' => ['directive' => 'MACs', 'found' => $value, 'weak' => $weak], 'matched_path' => 'etc/ssh/sshd_config'];
                },
            ],
        ];
    }

    private static function pwqualityRule(string $id, string $title, string $arg, callable $isCompliant): array
    {
        return [
            'id' => $id,
            'category' => 'Password Policy',
            'title' => $title,
            'description' => "Verifies the pam_pwquality.so '{$arg}' argument in system-auth/password-auth.",
            'severity' => 'MEDIUM',
            'remediation' => "Set '{$arg}' appropriately on the pam_pwquality.so line in /etc/pam.d/system-auth and /etc/pam.d/password-auth.",
            'reference_url' => null,
            'check' => function (array $facts) use ($arg, $isCompliant) {
                $value = self::pamModuleArgValue($facts, ['system_auth', 'password_auth'], 'pam_pwquality.so', $arg);

                return [
                    'status' => $isCompliant($value) ? 'pass' : 'fail',
                    'evidence' => ['module' => 'pam_pwquality.so', 'arg' => $arg, 'found' => $value],
                    'matched_path' => 'etc/pam.d/system-auth',
                ];
            },
        ];
    }

    private static function pamRules(): array
    {
        return [
            self::pwqualityRule('cis-pam-minlen', 'Password minimum length must be 14 or more', 'minlen', fn (?string $v) => self::toInt($v) !== null && self::toInt($v) >= 14),
            self::pwqualityRule('cis-pam-dcredit', 'Password policy must require a digit', 'dcredit', fn (?string $v) => self::toInt($v) !== null && self::toInt($v) <= -1),
            self::pwqualityRule('cis-pam-ucredit', 'Password policy must require an uppercase character', 'ucredit', fn (?string $v) => self::toInt($v) !== null && self::toInt($v) <= -1),
            self::pwqualityRule('cis-pam-ocredit', 'Password policy must require a special character', 'ocredit', fn (?string $v) => self::toInt($v) !== null && self::toInt($v) <= -1),
            self::pwqualityRule('cis-pam-lcredit', 'Password policy must require a lowercase character', 'lcredit', fn (?string $v) => self::toInt($v) !== null && self::toInt($v) <= -1),
            self::pwqualityRule('cis-pam-retry', 'Password policy retry count must be 3 or less', 'retry', fn (?string $v) => self::toInt($v) !== null && self::toInt($v) > 0 && self::toInt($v) <= 3),
            [
                'id' => 'cis-pam-faillock',
                'category' => 'Password Policy',
                'title' => 'Account lockout (faillock) must be configured',
                'description' => 'Verifies pam_faillock.so is present in system-auth or password-auth.',
                'severity' => 'MEDIUM',
                'remediation' => 'Add pam_faillock.so to /etc/pam.d/system-auth and /etc/pam.d/password-auth.',
                'reference_url' => null,
                'check' => function (array $facts) {
                    $present = self::pamModulePresent($facts, ['system_auth', 'password_auth'], 'pam_faillock.so');

                    return ['status' => $present ? 'pass' : 'fail', 'evidence' => ['module' => 'pam_faillock.so', 'present' => $present], 'matched_path' => 'etc/pam.d/system-auth'];
                },
            ],
            [
                'id' => 'cis-pam-remember',
                'category' => 'Password Policy',
                'title' => 'Password history (remember) must be configured',
                'description' => "Verifies the 'remember' argument is set on pam_unix.so/pam_pwhistory.so.",
                'severity' => 'MEDIUM',
                'remediation' => "Add 'remember=5' (or higher) to the pam_pwhistory.so/pam_unix.so line in /etc/pam.d/system-auth.",
                'reference_url' => null,
                'check' => function (array $facts) {
                    $value = self::pamModuleArgValue($facts, ['system_auth', 'password_auth'], 'pam_pwhistory.so', 'remember')
                        ?? self::pamModuleArgValue($facts, ['system_auth', 'password_auth'], 'pam_unix.so', 'remember');
                    $intValue = self::toInt($value);

                    return ['status' => $intValue !== null && $intValue >= 1 ? 'pass' : 'fail', 'evidence' => ['arg' => 'remember', 'found' => $value], 'matched_path' => 'etc/pam.d/system-auth'];
                },
            ],
        ];
    }

    private static function sudoersRules(): array
    {
        return [
            [
                'id' => 'cis-sudoers-no-blanket-nopasswd',
                'category' => 'Privilege Escalation',
                'title' => 'sudoers must not grant blanket NOPASSWD:ALL',
                'description' => 'Verifies etc/sudoers does not contain a blanket NOPASSWD:ALL entry.',
                'severity' => 'HIGH',
                'remediation' => 'Remove any "NOPASSWD: ALL" entries from /etc/sudoers.',
                'reference_url' => null,
                'check' => function (array $facts) {
                    $found = false;
                    foreach (self::sudoersLines($facts) as $line) {
                        if (preg_match('/NOPASSWD\s*:\s*ALL/i', $line)) {
                            $found = true;
                            break;
                        }
                    }

                    return ['status' => $found ? 'fail' : 'pass', 'evidence' => ['blanket_nopasswd' => $found], 'matched_path' => 'etc/sudoers'];
                },
            ],
            [
                'id' => 'cis-sudoers-secure-path',
                'category' => 'Privilege Escalation',
                'title' => 'sudoers must define secure_path',
                'description' => 'Verifies etc/sudoers sets Defaults secure_path.',
                'severity' => 'MEDIUM',
                'remediation' => 'Add "Defaults secure_path=..." to /etc/sudoers.',
                'reference_url' => null,
                'check' => fn (array $facts) => ['status' => self::sudoersContains($facts, 'secure_path') ? 'pass' : 'fail', 'evidence' => ['directive' => 'secure_path'], 'matched_path' => 'etc/sudoers'],
            ],
            [
                'id' => 'cis-sudoers-use-pty',
                'category' => 'Privilege Escalation',
                'title' => 'sudoers must set use_pty',
                'description' => 'Verifies etc/sudoers sets Defaults use_pty.',
                'severity' => 'LOW',
                'remediation' => 'Add "Defaults use_pty" to /etc/sudoers.',
                'reference_url' => null,
                'check' => fn (array $facts) => ['status' => self::sudoersContains($facts, 'use_pty') ? 'pass' : 'fail', 'evidence' => ['directive' => 'use_pty'], 'matched_path' => 'etc/sudoers'],
            ],
            [
                'id' => 'cis-sudoers-logfile',
                'category' => 'Privilege Escalation',
                'title' => 'sudoers must configure a logfile',
                'description' => 'Verifies etc/sudoers sets Defaults logfile.',
                'severity' => 'LOW',
                'remediation' => 'Add "Defaults logfile=/var/log/sudo.log" to /etc/sudoers.',
                'reference_url' => null,
                'check' => fn (array $facts) => ['status' => self::sudoersContains($facts, 'logfile') ? 'pass' : 'fail', 'evidence' => ['directive' => 'logfile'], 'matched_path' => 'etc/sudoers'],
            ],
        ];
    }

    private static function firewallRules(): array
    {
        $chains = ['INPUT' => 'HIGH', 'FORWARD' => 'MEDIUM'];
        $rules = [];

        foreach ($chains as $chain => $severity) {
            $rules[] = [
                'id' => 'cis-firewall-'.strtolower($chain).'-default-drop',
                'category' => 'Firewall',
                'title' => "iptables {$chain} chain default policy must be DROP or REJECT",
                'description' => "Verifies the default policy on the {$chain} chain.",
                'severity' => $severity,
                'remediation' => "Set the default policy on the {$chain} chain to DROP (iptables -P {$chain} DROP).",
                'reference_url' => null,
                'check' => fn (array $facts) => ['status' => self::firewallPolicyIsDropOrReject($facts, $chain) ? 'pass' : 'fail', 'evidence' => ['chain' => $chain, 'policy' => self::firewallChain($facts, $chain)['policy'] ?? null], 'matched_path' => null],
            ];
        }

        return $rules;
    }

    private static function sysctlRule(string $id, string $param, string $expected, string $title, string $severity): array
    {
        return [
            'id' => $id,
            'category' => 'Kernel Hardening',
            'title' => $title,
            'description' => "Verifies the sysctl parameter '{$param}' equals '{$expected}'.",
            'severity' => $severity,
            'remediation' => "Set \"{$param} = {$expected}\" in /etc/sysctl.conf (or a sysctl.d drop-in) and apply with sysctl -p.",
            'reference_url' => null,
            'check' => function (array $facts) use ($param, $expected) {
                $value = self::sysctlValue($facts, $param);

                return ['status' => $value === $expected ? 'pass' : 'fail', 'evidence' => ['param' => $param, 'found' => $value, 'expected' => $expected], 'matched_path' => null];
            },
        ];
    }

    private static function sysctlRules(): array
    {
        return [
            self::sysctlRule('cis-sysctl-ip-forward', 'net.ipv4.ip_forward', '0', 'IP forwarding must be disabled', 'MEDIUM'),
            self::sysctlRule('cis-sysctl-accept-redirects', 'net.ipv4.conf.all.accept_redirects', '0', 'ICMP redirects must not be accepted', 'MEDIUM'),
            self::sysctlRule('cis-sysctl-ignore-broadcasts', 'net.ipv4.icmp_echo_ignore_broadcasts', '1', 'Broadcast ICMP requests must be ignored', 'LOW'),
            self::sysctlRule('cis-sysctl-aslr', 'kernel.randomize_va_space', '2', 'ASLR must be fully enabled', 'HIGH'),
            self::sysctlRule('cis-sysctl-suid-dumpable', 'fs.suid_dumpable', '0', 'SUID core dumps must be disabled', 'MEDIUM'),
            self::sysctlRule('cis-sysctl-syncookies', 'net.ipv4.tcp_syncookies', '1', 'TCP SYN cookies must be enabled', 'MEDIUM'),
            self::sysctlRule('cis-sysctl-source-route', 'net.ipv4.conf.all.accept_source_route', '0', 'Source routed packets must not be accepted', 'MEDIUM'),
            self::sysctlRule('cis-sysctl-log-martians', 'net.ipv4.conf.all.log_martians', '1', 'Martian packets must be logged', 'LOW'),
        ];
    }

    private static function selinuxRule(): array
    {
        return [
            'id' => 'cis-selinux-enforcing',
            'category' => 'Mandatory Access Control',
            'title' => 'SELinux must be in enforcing mode',
            'description' => 'Verifies etc/selinux/config sets SELINUX=enforcing.',
            'severity' => 'HIGH',
            'remediation' => 'Set "SELINUX=enforcing" in /etc/selinux/config.',
            'reference_url' => null,
            'check' => function (array $facts) {
                $mode = $facts['selinux']['config_mode'] ?? null;

                return ['status' => strtolower((string) $mode) === 'enforcing' ? 'pass' : 'fail', 'evidence' => ['config_mode' => $mode], 'matched_path' => 'etc/selinux/config'];
            },
        ];
    }

    private static function auditdEnabledRule(): array
    {
        return [
            'id' => 'cis-auditd-enabled',
            'category' => 'Auditing',
            'title' => 'auditd service must be enabled',
            'description' => 'Verifies auditd.service is loaded via systemd.',
            'severity' => 'MEDIUM',
            'remediation' => 'Enable auditd (systemctl enable auditd).',
            'reference_url' => null,
            'check' => fn (array $facts) => ['status' => self::unitEnabled($facts, 'auditd.service') ? 'pass' : 'fail', 'evidence' => ['unit' => self::systemdUnit($facts, 'auditd.service')], 'matched_path' => null],
        ];
    }

    private static function auditdActiveRule(): array
    {
        return [
            'id' => 'cis-auditd-active',
            'category' => 'Auditing',
            'title' => 'auditd service must be active',
            'description' => 'Verifies auditd.service is active via systemd.',
            'severity' => 'MEDIUM',
            'remediation' => 'Start auditd (systemctl start auditd).',
            'reference_url' => null,
            'check' => fn (array $facts) => ['status' => self::unitActive($facts, 'auditd.service') ? 'pass' : 'fail', 'evidence' => ['unit' => self::systemdUnit($facts, 'auditd.service')], 'matched_path' => null],
        ];
    }

    private static function unnecessaryServiceRules(string $family): array
    {
        $services = [
            'telnet' => ['package' => $family === 'debian' ? 'telnetd' : 'telnet-server', 'units' => ['telnet.socket', 'telnet.service']],
            'rsh' => ['package' => $family === 'debian' ? 'rsh-server' : 'rsh-server', 'units' => ['rsh.socket', 'rexec.socket', 'rlogin.socket']],
            'ypserv' => ['package' => 'ypserv', 'units' => ['ypserv.service']],
            'tftp' => ['package' => $family === 'debian' ? 'tftpd' : 'tftp-server', 'units' => ['tftp.socket', 'tftp.service']],
            'xinetd' => ['package' => 'xinetd', 'units' => ['xinetd.service']],
        ];

        $rules = [];
        foreach ($services as $name => $def) {
            $rules[] = [
                'id' => "cis-unnecessary-service-{$name}",
                'category' => 'Unnecessary Services',
                'title' => ucfirst($name).' must not be installed or active',
                'description' => "Verifies the {$def['package']} package is absent and its unit(s) are not active.",
                'severity' => 'MEDIUM',
                'remediation' => "Remove the {$def['package']} package and disable/mask its unit(s).",
                'reference_url' => null,
                'check' => function (array $facts) use ($def) {
                    $installed = self::packageInstalled($facts, $def['package']);
                    $active = false;
                    foreach ($def['units'] as $unit) {
                        if (self::unitActive($facts, $unit)) {
                            $active = true;
                            break;
                        }
                    }

                    $status = (! $installed && ! $active) ? 'pass' : 'fail';

                    return ['status' => $status, 'evidence' => ['package_installed' => $installed, 'unit_active' => $active], 'matched_path' => null];
                },
            ];
        }

        return $rules;
    }
}
