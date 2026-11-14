<?php

namespace App\Services\Compliance\Rulesets;

use App\Services\Compliance\Rulesets\Concerns\InspectsFacts;

/**
 * Static-capture subset of the CIS Docker Benchmark. sosreport's generic
 * docker plugin captures `docker info` + etc/docker/daemon.json — it does
 * NOT reliably include per-container `docker inspect` JSON, so the official
 * benchmark's section 5 (per-container runtime checks: capabilities,
 * read-only rootfs, PID/mount namespace sharing, etc.) is out of scope here.
 * Every rule below only evidences what the daemon-level static capture can
 * actually show, which is why this ruleset is intentionally smaller than
 * CIS/STIG/Exposure. Every rule resolves to 'not_applicable' when no docker
 * plugin data was captured at all (facts['docker'] is null).
 */
class DockerBenchRuleset
{
    use InspectsFacts;

    public static function rules(array $context): array
    {
        $rules = array_merge(
            self::daemonConfigRules(),
            self::securityRules(),
            self::auditRules(),
        );

        return array_map(function (array $rule) {
            $rule['ruleset'] = 'docker_bench';
            $rule['applicable'] = fn (array $facts) => ! empty($facts['docker']);

            return $rule;
        }, $rules);
    }

    private static function daemonRule(string $id, string $title, string $severity, string $remediation, \Closure $check): array
    {
        return [
            'id' => "docker-bench-{$id}",
            'category' => 'Docker Daemon Configuration',
            'title' => $title,
            'description' => "Verifies the '{$id}' setting from docker info / etc/docker/daemon.json.",
            'severity' => $severity,
            'remediation' => $remediation,
            'reference_url' => null,
            'check' => $check,
        ];
    }

    private static function daemonConfigRules(): array
    {
        return [
            self::daemonRule('icc', 'Inter-container communication should be restricted', 'MEDIUM', 'Set "icc": false in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'icc');

                return self::boolResult($value === false, ['icc' => $value]);
            }),
            self::daemonRule('live-restore', 'Docker live restore should be enabled', 'LOW', 'Set "live-restore": true in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'live-restore');

                return self::boolResult($value === true, ['live-restore' => $value]);
            }),
            self::daemonRule('userland-proxy', 'Docker userland proxy should be disabled', 'LOW', 'Set "userland-proxy": false in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'userland-proxy');

                return self::boolResult($value === false, ['userland-proxy' => $value]);
            }),
            self::daemonRule('no-new-privileges', 'Docker no-new-privileges should be enabled', 'MEDIUM', 'Set "no-new-privileges": true in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'no-new-privileges');

                return self::boolResult($value === true, ['no-new-privileges' => $value]);
            }),
            self::daemonRule('log-level', 'Docker daemon log level should be configured', 'LOW', 'Set "log-level" in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'log-level');

                return self::boolResult($value !== null && $value !== '', ['log-level' => $value]);
            }),
            self::daemonRule('logging-driver', 'Docker logging driver should be configured', 'LOW', 'Set "log-driver" in etc/docker/daemon.json (or configure it via docker info).', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'log-driver') ?? ($f['docker']['logging_driver'] ?? null);

                return self::boolResult($value !== null && $value !== '', ['logging_driver' => $value]);
            }),
            self::daemonRule('default-ulimits', 'Default ulimits should be set', 'LOW', 'Set "default-ulimits" in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'default-ulimits');

                return self::boolResult(is_array($value) && $value !== [], ['default-ulimits' => $value]);
            }),
            self::daemonRule('no-insecure-registries', 'No insecure registries should be configured', 'HIGH', 'Remove any "insecure-registries" entries from etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'insecure-registries');

                return self::boolResult(empty($value), ['insecure-registries' => $value]);
            }),
            self::daemonRule('storage-driver', 'Storage driver should not be devicemapper (loopback)', 'MEDIUM', 'Migrate to overlay2 (or another non-loopback storage driver).', function (array $f) {
                $value = $f['docker']['storage_driver'] ?? null;

                return self::boolResult($value !== 'devicemapper', ['storage_driver' => $value]);
            }),
            self::daemonRule('debug-disabled', 'Docker daemon debug mode should be disabled', 'LOW', 'Set "debug": false (or remove it) in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'debug');

                return self::boolResult($value !== true, ['debug' => $value]);
            }),
            self::daemonRule('experimental-disabled', 'Docker daemon experimental features should be disabled', 'LOW', 'Set "experimental": false (or remove it) in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'experimental');

                return self::boolResult($value !== true, ['experimental' => $value]);
            }),
            self::daemonRule('authorization-plugins', 'An authorization plugin should be configured', 'MEDIUM', 'Configure "authorization-plugins" in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'authorization-plugins');

                return self::boolResult(is_array($value) && $value !== [], ['authorization-plugins' => $value]);
            }),
            self::daemonRule('userns-remap', 'User namespace remapping should be configured', 'MEDIUM', 'Configure "userns-remap" in etc/docker/daemon.json.', function (array $f) {
                $value = self::dockerDaemonConfig($f, 'userns-remap');

                return self::boolResult($value !== null && $value !== '', ['userns-remap' => $value]);
            }),
            self::daemonRule('log-opts-max-size', 'Container log rotation max-size should be set', 'LOW', 'Set "log-opts": {"max-size": "10m"} in etc/docker/daemon.json.', function (array $f) {
                $opts = self::dockerDaemonConfig($f, 'log-opts');

                return self::boolResult(is_array($opts) && ! empty($opts['max-size']), ['log-opts' => $opts]);
            }),
            self::daemonRule('log-opts-max-file', 'Container log rotation max-file should be set', 'LOW', 'Set "log-opts": {"max-file": "3"} in etc/docker/daemon.json.', function (array $f) {
                $opts = self::dockerDaemonConfig($f, 'log-opts');

                return self::boolResult(is_array($opts) && ! empty($opts['max-file']), ['log-opts' => $opts]);
            }),
            self::daemonRule('min-server-version', 'Docker Engine should be a reasonably current version', 'MEDIUM', 'Upgrade the Docker Engine to 20.10 or later.', function (array $f) {
                $version = $f['docker']['server_version'] ?? null;
                $major = $version !== null ? (int) explode('.', $version)[0] : null;

                return self::boolResult($major !== null && $major >= 20, ['server_version' => $version]);
            }),
        ];
    }

    private static function securityRules(): array
    {
        return [
            [
                'id' => 'docker-bench-security-options',
                'category' => 'Docker Daemon Configuration',
                'title' => 'Security options should include seccomp, apparmor or selinux',
                'description' => "Verifies docker info's Security Options include a MAC/seccomp mechanism.",
                'severity' => 'HIGH',
                'remediation' => 'Ensure seccomp is not disabled and AppArmor/SELinux confinement is active for the docker daemon.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $options = array_map('strtolower', $f['docker']['security_options'] ?? []);
                    $present = array_values(array_intersect($options, ['seccomp', 'apparmor', 'selinux']));

                    return self::boolResult($present !== [], ['security_options' => $f['docker']['security_options'] ?? []]);
                },
            ],
            [
                'id' => 'docker-bench-tls-remote-access',
                'category' => 'Docker Daemon Configuration',
                'title' => 'Remote (tcp://) daemon access must require TLS',
                'description' => "Verifies any 'tcp://' entry in daemon_config['hosts'] is paired with tlsverify.",
                'severity' => 'CRITICAL',
                'remediation' => 'Enable "tlsverify": true and configure tlscacert/tlscert/tlskey, or remove the tcp:// listener.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $hosts = self::dockerDaemonConfig($f, 'hosts') ?? [];
                    $hasTcp = false;
                    foreach ((array) $hosts as $host) {
                        if (is_string($host) && str_starts_with($host, 'tcp://')) {
                            $hasTcp = true;
                            break;
                        }
                    }

                    if (! $hasTcp) {
                        return ['status' => 'pass', 'evidence' => ['hosts' => $hosts], 'matched_path' => null];
                    }

                    $tlsVerify = self::dockerDaemonConfig($f, 'tlsverify');

                    return self::boolResult($tlsVerify === true, ['hosts' => $hosts, 'tlsverify' => $tlsVerify]);
                },
            ],
            [
                'id' => 'docker-bench-selinux-enabled',
                'category' => 'Docker Daemon Configuration',
                'title' => 'selinux-enabled should not be explicitly disabled',
                'description' => "Verifies daemon_config['selinux-enabled'] is not explicitly false.",
                'severity' => 'MEDIUM',
                'remediation' => 'Remove "selinux-enabled": false (or set it to true) in etc/docker/daemon.json.',
                'reference_url' => null,
                'check' => function (array $f) {
                    $value = self::dockerDaemonConfig($f, 'selinux-enabled');

                    return self::boolResult($value !== false, ['selinux-enabled' => $value]);
                },
            ],
        ];
    }

    private static function auditRules(): array
    {
        $watches = [
            'audit-dockerd' => '/usr/bin/dockerd',
            'audit-containerd' => '/usr/bin/containerd',
            'audit-runc' => '/usr/bin/runc',
            'audit-docker-service' => '/usr/lib/systemd/system/docker.service',
            'audit-docker-socket' => '/usr/lib/systemd/system/docker.socket',
            'audit-etc-docker' => '/etc/docker',
            'audit-etc-default-docker' => '/etc/default/docker',
            'audit-daemon-json' => '/etc/docker/daemon.json',
            'audit-var-lib-docker' => '/var/lib/docker',
        ];

        $rules = [];
        foreach ($watches as $id => $path) {
            $rules[] = [
                'id' => "docker-bench-{$id}",
                'category' => 'Auditing',
                'title' => "auditd should watch {$path}",
                'description' => "Verifies an auditd '-w {$path}' rule is present.",
                'severity' => 'MEDIUM',
                'remediation' => "Add '-w {$path} -p wa -k docker' to the audit rules and restart auditd.",
                'reference_url' => null,
                'check' => fn (array $f) => self::boolResult(self::auditdWatchesPath($f, $path), ['watched_path' => $path]),
            ];
        }

        return $rules;
    }

    private static function boolResult(bool $pass, array $evidence): array
    {
        return ['status' => $pass ? 'pass' : 'fail', 'evidence' => $evidence, 'matched_path' => null];
    }
}
