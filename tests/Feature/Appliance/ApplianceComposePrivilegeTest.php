<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Guards the R11 de-privileging of the shipped appliance compose: the app
 * container must NOT run with `privileged: true`, and must instead carry the
 * minimal capability/seccomp/device set the LUKS vault feature needs.
 */
it('does not run the appliance app container as privileged', function () {
    $app = Yaml::parseFile(base_path('docker-compose.appliance.yml'))['services']['app'];

    expect($app['privileged'] ?? false)->toBeFalse();
});

it('grants the app container exactly the caps LUKS needs', function () {
    $app = Yaml::parseFile(base_path('docker-compose.appliance.yml'))['services']['app'];

    expect($app['cap_add'] ?? [])->toContain('SYS_ADMIN')
        ->and($app['security_opt'] ?? [])->toContain('apparmor:unconfined')
        ->and($app['security_opt'] ?? [])->toContain('seccomp:unconfined')
        ->and(implode("\n", $app['device_cgroup_rules'] ?? []))->toContain('b 7:* rmw');
});
