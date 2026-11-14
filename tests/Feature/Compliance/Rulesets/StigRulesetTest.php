<?php

use App\Services\Compliance\RuleEngine;
use App\Services\Compliance\Rulesets\StigRuleset;

function stigRhel9Context(): array
{
    return ['os_family' => 'rhel', 'os_id' => 'rhel', 'os_version_id' => '9.4'];
}

function stigHardenedFacts(): array
{
    return [
        'ssh' => ['directives' => [
            'permitrootlogin' => 'no',
            'clientalivecountmax' => '1',
            'clientaliveinterval' => '600',
            'banner' => '/etc/issue',
            'permitemptypasswords' => 'no',
            'usepam' => 'yes',
            'x11forwarding' => 'no',
            'gssapiauthentication' => 'no',
            'kerberosauthentication' => 'no',
            'ignorerhosts' => 'yes',
            'hostbasedauthentication' => 'no',
            'strictmodes' => 'yes',
            'compression' => 'no',
            'ciphers' => 'aes256-gcm@openssh.com',
            'macs' => 'hmac-sha2-512-etm@openssh.com',
        ]],
        'pam' => [
            'system_auth' => [
                'password requisite pam_pwquality.so retry=3 minlen=15 dcredit=-1 ucredit=-1 ocredit=-1 lcredit=-1',
                'auth required pam_faillock.so preauth',
            ],
            'password_auth' => [
                'password requisite pam_pwquality.so retry=3 minlen=15 dcredit=-1 ucredit=-1 ocredit=-1 lcredit=-1',
                'auth required pam_faillock.so preauth',
            ],
        ],
        'sudoers' => ['raw_lines' => [
            'Defaults secure_path=/sbin:/bin',
        ]],
        'sysctl' => [
            ['Name' => 'net.ipv4.conf.default.accept_redirects', 'Value' => '0'],
            ['Name' => 'net.ipv4.conf.all.accept_redirects', 'Value' => '0'],
            ['Name' => 'net.ipv4.icmp_echo_ignore_broadcasts', 'Value' => '1'],
            ['Name' => 'net.ipv4.icmp_ignore_bogus_error_responses', 'Value' => '1'],
            ['Name' => 'net.ipv4.conf.all.send_redirects', 'Value' => '0'],
            ['Name' => 'net.ipv4.conf.default.send_redirects', 'Value' => '0'],
            ['Name' => 'net.ipv6.conf.default.accept_redirects', 'Value' => '0'],
            ['Name' => 'net.ipv6.conf.all.accept_redirects', 'Value' => '0'],
        ],
        'systemd' => ['systemd' => [
            ['unit' => 'auditd.service', 'type' => 'service', 'loaded' => 'loaded', 'active' => 'active', 'sub' => 'running', 'job' => '', 'description' => 'Audit'],
        ]],
        'selinux' => ['config_mode' => 'enforcing', 'type' => 'targeted'],
        'auditd' => ['config' => [
            'admin_space_left_action' => 'halt',
            'disk_error_action' => 'syslog',
            'freq' => '50',
        ]],
        'login_defs' => ['directives' => [
            'pass_min_days' => '1',
            'pass_max_days' => '60',
        ]],
        'packages' => [
            ['Name' => 'audit-3.1.2-4.el9.x86_64', 'Date' => '2024-01-01'],
            ['Name' => 'sudo-1.9.5p2-10.el9.x86_64', 'Date' => '2024-01-01'],
        ],
        'docker' => null,
        'network' => [],
    ];
}

function stigMisconfiguredFacts(): array
{
    return [
        'ssh' => ['directives' => [
            'permitrootlogin' => 'yes',
            'usepam' => 'no',
        ]],
        'pam' => [],
        'sudoers' => ['raw_lines' => [
            '%wheel ALL=(ALL) NOPASSWD: ALL',
        ]],
        'sysctl' => [],
        'systemd' => ['systemd' => []],
        'selinux' => ['config_mode' => 'permissive', 'type' => null],
        'auditd' => ['config' => []],
        'login_defs' => ['directives' => ['pass_max_days' => '99999']],
        'packages' => [],
        'docker' => null,
        'network' => [],
    ];
}

function stigStatusById(array $results, string $id): ?string
{
    foreach ($results as $result) {
        if ($result['rule_id'] === $id) {
            return $result['status'];
        }
    }

    return null;
}

it('produces a rule count within the documented 40-60 band for a real RHEL9 context', function () {
    $rules = StigRuleset::rules(stigRhel9Context());

    expect(count($rules))->toBeGreaterThanOrEqual(40)->toBeLessThanOrEqual(60);
});

it('returns no rules for an os_family with no matching json/stig file', function () {
    $rules = StigRuleset::rules(['os_family' => 'unknown', 'os_id' => 'unknown-os', 'os_version_id' => '1']);

    expect($rules)->toBe([]);
});

it('resolves hardened facts to pass for representative rule ids', function () {
    $results = (new RuleEngine)->evaluate(StigRuleset::rules(stigRhel9Context()), stigHardenedFacts());

    expect(stigStatusById($results, 'stig-ssh_permit_root_login'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-ssh_empty_password'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-ssh_pam'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-selinux_targeted'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-audit_package'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-audit_service_enabled'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-sudo_installed'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-password_min_length'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-password_max_lifetime_logindefs'))->toBe('pass')
        ->and(stigStatusById($results, 'stig-sysctl_icmp_redirect_accept_v4'))->toBe('pass');
});

it('resolves misconfigured facts to fail for representative rule ids', function () {
    $results = (new RuleEngine)->evaluate(StigRuleset::rules(stigRhel9Context()), stigMisconfiguredFacts());

    expect(stigStatusById($results, 'stig-ssh_permit_root_login'))->toBe('fail')
        ->and(stigStatusById($results, 'stig-ssh_pam'))->toBe('fail')
        ->and(stigStatusById($results, 'stig-selinux_targeted'))->toBe('fail')
        ->and(stigStatusById($results, 'stig-audit_package'))->toBe('fail')
        ->and(stigStatusById($results, 'stig-audit_service_enabled'))->toBe('fail')
        ->and(stigStatusById($results, 'stig-sudo_password'))->toBe('fail')
        ->and(stigStatusById($results, 'stig-password_max_lifetime_logindefs'))->toBe('fail')
        ->and(stigStatusById($results, 'stig-faillock_system_auth'))->toBe('fail');
});
