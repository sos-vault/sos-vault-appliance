<?php

use App\Services\Compliance\RuleEngine;
use App\Services\Compliance\Rulesets\CisRuleset;

function cisRhel9Context(): array
{
    return ['os_family' => 'rhel', 'os_id' => 'rhel', 'os_version_id' => '9.4'];
}

function cisHardenedFacts(): array
{
    return [
        'ssh' => ['directives' => [
            'protocol' => '2',
            'loglevel' => 'info',
            'x11forwarding' => 'no',
            'permitrootlogin' => 'no',
            'permitemptypasswords' => 'no',
            'ignorerhosts' => 'yes',
            'hostbasedauthentication' => 'no',
            'permituserenvironment' => 'no',
            'banner' => '/etc/issue.net',
            'maxauthtries' => '4',
            'clientaliveinterval' => '300',
            'logingracetime' => '60',
            'allowtcpforwarding' => 'no',
            'ciphers' => 'chacha20-poly1305@openssh.com,aes256-gcm@openssh.com',
            'macs' => 'hmac-sha2-512-etm@openssh.com',
        ]],
        'pam' => [
            'system_auth' => [
                'password requisite pam_pwquality.so try_first_pass retry=3 minlen=14 dcredit=-1 ucredit=-1 ocredit=-1 lcredit=-1',
                'password sufficient pam_unix.so sha512 remember=5',
                'auth required pam_faillock.so preauth',
            ],
            'password_auth' => [
                'password requisite pam_pwquality.so try_first_pass retry=3 minlen=14 dcredit=-1 ucredit=-1 ocredit=-1 lcredit=-1',
                'auth required pam_faillock.so preauth',
            ],
        ],
        'sudoers' => ['raw_lines' => [
            'Defaults secure_path=/sbin:/bin:/usr/sbin:/usr/bin',
            'Defaults use_pty',
            'Defaults logfile=/var/log/sudo.log',
            '%wheel ALL=(ALL) ALL',
        ]],
        'firewall' => [
            'INPUT' => ['title' => 'Chain INPUT', 'policy' => 'DROP,', 'data' => []],
            'FORWARD' => ['title' => 'Chain FORWARD', 'policy' => 'DROP,', 'data' => []],
        ],
        'sysctl' => [
            ['Name' => 'net.ipv4.ip_forward', 'Value' => '0'],
            ['Name' => 'net.ipv4.conf.all.accept_redirects', 'Value' => '0'],
            ['Name' => 'net.ipv4.icmp_echo_ignore_broadcasts', 'Value' => '1'],
            ['Name' => 'kernel.randomize_va_space', 'Value' => '2'],
            ['Name' => 'fs.suid_dumpable', 'Value' => '0'],
            ['Name' => 'net.ipv4.tcp_syncookies', 'Value' => '1'],
            ['Name' => 'net.ipv4.conf.all.accept_source_route', 'Value' => '0'],
            ['Name' => 'net.ipv4.conf.all.log_martians', 'Value' => '1'],
        ],
        'systemd' => ['systemd' => [
            ['unit' => 'auditd.service', 'type' => 'service', 'loaded' => 'loaded', 'active' => 'active', 'sub' => 'running', 'job' => '', 'description' => 'Audit'],
        ]],
        'selinux' => ['config_mode' => 'enforcing', 'type' => 'targeted'],
        'packages' => [
            ['Name' => 'openssh-server-8.7p1-38.el9.x86_64', 'Date' => '2024-01-01'],
        ],
        'docker' => null,
        'network' => [],
    ];
}

function cisMisconfiguredFacts(): array
{
    return [
        'ssh' => ['directives' => [
            'permitrootlogin' => 'yes',
            'permitemptypasswords' => 'yes',
            'x11forwarding' => 'yes',
        ]],
        'pam' => [],
        'sudoers' => ['raw_lines' => [
            '%wheel ALL=(ALL) NOPASSWD: ALL',
        ]],
        'firewall' => [
            'INPUT' => ['title' => 'Chain INPUT', 'policy' => 'ACCEPT', 'data' => []],
        ],
        'sysctl' => [
            ['Name' => 'net.ipv4.ip_forward', 'Value' => '1'],
        ],
        'systemd' => ['systemd' => [
            ['unit' => 'telnet.socket', 'type' => 'socket', 'loaded' => 'loaded', 'active' => 'active', 'sub' => 'running', 'job' => '', 'description' => 'Telnet'],
        ]],
        'selinux' => ['config_mode' => 'disabled', 'type' => null],
        'packages' => [
            ['Name' => 'telnet-server-0.17-85.el9.x86_64', 'Date' => '2024-01-01'],
        ],
        'docker' => null,
        'network' => [],
    ];
}

function cisStatusById(array $results, string $id): ?string
{
    foreach ($results as $result) {
        if ($result['rule_id'] === $id) {
            return $result['status'];
        }
    }

    return null;
}

it('produces a rule count within the documented 40-60 band for a real RHEL9 context', function () {
    $rules = CisRuleset::rules(cisRhel9Context());

    expect(count($rules))->toBeGreaterThanOrEqual(40)->toBeLessThanOrEqual(60);
});

it('resolves hardened facts to pass for representative rule ids', function () {
    $results = (new RuleEngine)->evaluate(CisRuleset::rules(cisRhel9Context()), cisHardenedFacts());

    expect(cisStatusById($results, 'cis-ssh-permitrootlogin'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-ssh-permitemptypasswords'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-ssh-x11forwarding'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-ssh-maxauthtries'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-pam-minlen'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-pam-faillock'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-sudoers-no-blanket-nopasswd'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-firewall-input-default-drop'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-sysctl-ip-forward'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-selinux-enforcing'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-auditd-active'))->toBe('pass')
        ->and(cisStatusById($results, 'cis-unnecessary-service-telnet'))->toBe('pass');
});

it('resolves misconfigured facts to fail for representative rule ids', function () {
    $results = (new RuleEngine)->evaluate(CisRuleset::rules(cisRhel9Context()), cisMisconfiguredFacts());

    expect(cisStatusById($results, 'cis-ssh-permitrootlogin'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-ssh-permitemptypasswords'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-ssh-x11forwarding'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-sudoers-no-blanket-nopasswd'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-firewall-input-default-drop'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-sysctl-ip-forward'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-selinux-enforcing'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-auditd-active'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-unnecessary-service-telnet'))->toBe('fail')
        ->and(cisStatusById($results, 'cis-pam-faillock'))->toBe('fail');
});
