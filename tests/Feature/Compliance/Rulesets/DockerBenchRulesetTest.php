<?php

use App\Services\Compliance\RuleEngine;
use App\Services\Compliance\Rulesets\DockerBenchRuleset;

function dockerBenchHardenedFacts(): array
{
    return [
        'docker' => [
            'docker_root_dir' => '/var/lib/docker',
            'storage_driver' => 'overlay2',
            'logging_driver' => 'json-file',
            'server_version' => '24.0.7',
            'security_options' => ['apparmor', 'seccomp'],
            'daemon_config' => [
                'icc' => false,
                'live-restore' => true,
                'userland-proxy' => false,
                'no-new-privileges' => true,
                'log-level' => 'info',
                'log-driver' => 'json-file',
                'default-ulimits' => ['nofile' => ['Name' => 'nofile', 'Hard' => 64000, 'Soft' => 64000]],
                'insecure-registries' => [],
                'debug' => false,
                'experimental' => false,
                'authorization-plugins' => ['authz-broker'],
                'userns-remap' => 'default',
                'log-opts' => ['max-size' => '10m', 'max-file' => '3'],
                'selinux-enabled' => true,
            ],
        ],
        'auditd' => ['watched_paths' => [
            '/usr/bin/dockerd',
            '/usr/bin/containerd',
            '/usr/bin/runc',
            '/usr/lib/systemd/system/docker.service',
            '/usr/lib/systemd/system/docker.socket',
            '/etc/docker',
            '/etc/default/docker',
            '/etc/docker/daemon.json',
            '/var/lib/docker',
        ]],
    ];
}

function dockerBenchMisconfiguredFacts(): array
{
    return [
        'docker' => [
            'docker_root_dir' => '/var/lib/docker',
            'storage_driver' => 'devicemapper',
            'logging_driver' => null,
            'server_version' => '17.03.2',
            'security_options' => [],
            'daemon_config' => [
                'icc' => true,
                'insecure-registries' => ['registry.local:5000'],
                'debug' => true,
                'hosts' => ['tcp://0.0.0.0:2375'],
            ],
        ],
        'auditd' => ['watched_paths' => []],
    ];
}

function dockerBenchStatusById(array $results, string $id): ?string
{
    foreach ($results as $result) {
        if ($result['rule_id'] === $id) {
            return $result['status'];
        }
    }

    return null;
}

it('produces a rule count within the documented 25-35 band', function () {
    $rules = DockerBenchRuleset::rules([]);

    expect(count($rules))->toBeGreaterThanOrEqual(25)->toBeLessThanOrEqual(35);
});

it('resolves every rule to not_applicable when no docker plugin data was captured', function () {
    $results = (new RuleEngine)->evaluate(DockerBenchRuleset::rules([]), ['docker' => null]);

    expect($results)->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result['status'])->toBe('not_applicable');
    }
});

it('resolves hardened docker facts to pass for representative rule ids', function () {
    $results = (new RuleEngine)->evaluate(DockerBenchRuleset::rules([]), dockerBenchHardenedFacts());

    expect(dockerBenchStatusById($results, 'docker-bench-icc'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-live-restore'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-no-new-privileges'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-no-insecure-registries'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-storage-driver'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-security-options'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-debug-disabled'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-min-server-version'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-audit-dockerd'))->toBe('pass')
        ->and(dockerBenchStatusById($results, 'docker-bench-audit-var-lib-docker'))->toBe('pass');
});

it('resolves misconfigured docker facts to fail for representative rule ids', function () {
    $results = (new RuleEngine)->evaluate(DockerBenchRuleset::rules([]), dockerBenchMisconfiguredFacts());

    expect(dockerBenchStatusById($results, 'docker-bench-icc'))->toBe('fail')
        ->and(dockerBenchStatusById($results, 'docker-bench-no-insecure-registries'))->toBe('fail')
        ->and(dockerBenchStatusById($results, 'docker-bench-storage-driver'))->toBe('fail')
        ->and(dockerBenchStatusById($results, 'docker-bench-security-options'))->toBe('fail')
        ->and(dockerBenchStatusById($results, 'docker-bench-debug-disabled'))->toBe('fail')
        ->and(dockerBenchStatusById($results, 'docker-bench-min-server-version'))->toBe('fail')
        ->and(dockerBenchStatusById($results, 'docker-bench-tls-remote-access'))->toBe('fail')
        ->and(dockerBenchStatusById($results, 'docker-bench-audit-dockerd'))->toBe('fail');
});
