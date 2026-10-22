<?php

use App\Services\Compliance\RuleEngine;
use App\Services\Compliance\Rulesets\ExposureRuleset;

function exposureRhel9Context(): array
{
    return ['os_family' => 'rhel', 'os_id' => 'rhel', 'os_version_id' => '9.4'];
}

function exposureHardenedFacts(): array
{
    return [
        'os' => ['family' => 'rhel', 'id' => 'rhel', 'version_id' => '9.4', 'name' => 'RHEL', 'pretty_name' => 'RHEL 9.4'],
        'ssh' => ['directives' => [
            'permitrootlogin' => 'no',
            'permitemptypasswords' => 'no',
            'hostbasedauthentication' => 'no',
            'x11forwarding' => 'no',
            'allowusers' => 'deploy',
        ]],
        'pam' => [
            'system_auth' => ['auth required pam_unix.so'],
            'password_auth' => ['auth required pam_unix.so'],
        ],
        'sudoers' => ['raw_lines' => ['%wheel ALL=(ALL) ALL']],
        'firewall' => [
            'INPUT' => ['title' => 'Chain INPUT', 'policy' => 'DROP,', 'data' => []],
            'FORWARD' => ['title' => 'Chain FORWARD', 'policy' => 'DROP,', 'data' => []],
            'OUTPUT' => ['title' => 'Chain OUTPUT', 'policy' => 'DROP,', 'data' => []],
        ],
        'systemd' => ['systemd' => []],
        'packages' => [],
        'network' => [
            ['Proto' => 'tcp', 'State' => 'LISTEN', 'Local_Address' => '127.0.0.1:22'],
            ['Proto' => 'tcp', 'State' => 'LISTEN', 'Local_Address' => '127.0.0.1:3306'],
        ],
    ];
}

function exposureExposedFacts(): array
{
    return [
        'os' => ['family' => 'rhel', 'id' => 'rhel', 'version_id' => '6.10', 'name' => 'RHEL', 'pretty_name' => 'RHEL 6.10'],
        'ssh' => ['directives' => [
            'permitrootlogin' => 'yes',
            'permitemptypasswords' => 'yes',
        ]],
        'pam' => [
            'system_auth' => ['auth sufficient pam_unix.so nullok'],
        ],
        'sudoers' => ['raw_lines' => ['%wheel ALL=(ALL) NOPASSWD: ALL']],
        'firewall' => [
            'INPUT' => ['title' => 'Chain INPUT', 'policy' => 'ACCEPT', 'data' => []],
        ],
        'systemd' => ['systemd' => [
            ['unit' => 'telnet.socket', 'type' => 'socket', 'loaded' => 'loaded', 'active' => 'active', 'sub' => 'running', 'job' => '', 'description' => 'Telnet'],
        ]],
        'packages' => [
            ['Name' => 'telnet-server-0.17-85.el9.x86_64', 'Date' => '2024-01-01'],
        ],
        'network' => [
            ['Proto' => 'tcp', 'State' => 'LISTEN', 'Local_Address' => '0.0.0.0:3306'],
        ],
    ];
}

function exposureStatusById(array $results, string $id): ?string
{
    foreach ($results as $result) {
        if ($result['rule_id'] === $id) {
            return $result['status'];
        }
    }

    return null;
}

it('produces a rule count within the documented 40-60 band for a real RHEL9 context', function () {
    $rules = ExposureRuleset::rules(exposureRhel9Context());

    expect(count($rules))->toBeGreaterThanOrEqual(40)->toBeLessThanOrEqual(60);
});

it('resolves hardened facts to pass for representative rule ids', function () {
    $results = (new RuleEngine)->evaluate(ExposureRuleset::rules(exposureRhel9Context()), exposureHardenedFacts());

    expect(exposureStatusById($results, 'exposure-firewall-input-default-accept'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-port-3306-public'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-port-22-public'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-sudoers-nopasswd-all'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-pam-nullok-system-auth'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-ssh-permit-root-login-yes'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-package-telnet-server'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-service-telnet-socket'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-os-eol'))->toBe('pass')
        ->and(exposureStatusById($results, 'exposure-ssh-no-user-restriction'))->toBe('pass');
});

it('resolves exposed facts to fail for representative rule ids', function () {
    $results = (new RuleEngine)->evaluate(ExposureRuleset::rules(exposureRhel9Context()), exposureExposedFacts());

    expect(exposureStatusById($results, 'exposure-firewall-input-default-accept'))->toBe('fail')
        ->and(exposureStatusById($results, 'exposure-port-3306-public'))->toBe('fail')
        ->and(exposureStatusById($results, 'exposure-sudoers-nopasswd-all'))->toBe('fail')
        ->and(exposureStatusById($results, 'exposure-pam-nullok-system-auth'))->toBe('fail')
        ->and(exposureStatusById($results, 'exposure-ssh-permit-root-login-yes'))->toBe('fail')
        ->and(exposureStatusById($results, 'exposure-package-telnet-server'))->toBe('fail')
        ->and(exposureStatusById($results, 'exposure-service-telnet-socket'))->toBe('fail')
        ->and(exposureStatusById($results, 'exposure-os-eol'))->toBe('fail');
});
