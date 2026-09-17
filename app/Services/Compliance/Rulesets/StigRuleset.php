<?php

namespace App\Services\Compliance\Rulesets;

use App\Services\Compliance\Rulesets\Concerns\InspectsFacts;

/**
 * DISA STIG-sourced checks. Title/description/severity/remediation text is
 * never hand-written here — it is loaded from json/stig/<slug>.json by
 * ruleVersion via stigText(), so the wording always matches the shipped
 * content pack. Only two slugs currently have a verified concept map:
 * red_hat_enterprise_linux_9 (full coverage) and red_hat_enterprise_linux_8
 * (partial — many RHEL 8 STIG rule IDs differ enough from RHEL 9's that they
 * need their own verified mapping, done here for the concepts confirmed to
 * exist in that revision). Other json/stig/*.json files exist and
 * resolveSlug() recognises their OS contexts, but without a verified
 * ruleVersion map for them rules() safely returns no rows for those contexts
 * rather than guessing an ID that might not exist.
 */
class StigRuleset
{
    use InspectsFacts;

    private const WEAK_CIPHERS = ['3des-cbc', 'arcfour', 'blowfish-cbc', 'cast128-cbc', 'aes128-cbc', 'aes192-cbc', 'aes256-cbc'];

    private const WEAK_MACS = ['hmac-md5', 'hmac-sha1', 'hmac-md5-96', 'hmac-sha1-96', 'umac-64'];

    private const RULE_VERSIONS = [
        'red_hat_enterprise_linux_9' => [
            'ssh_permit_root_login' => 'RHEL-09-255045',
            'ssh_client_alive_count' => 'RHEL-09-255095',
            'ssh_client_alive_interval' => 'RHEL-09-255100',
            'ssh_banner' => 'RHEL-09-255025',
            'ssh_empty_password' => 'RHEL-09-255040',
            'ssh_pam' => 'RHEL-09-255050',
            'ssh_x11forwarding' => 'RHEL-09-255155',
            'ssh_gssapi' => 'RHEL-09-255135',
            'ssh_kerberos' => 'RHEL-09-255140',
            'ssh_rhosts' => 'RHEL-09-255145',
            'ssh_known_hosts' => 'RHEL-09-255150',
            'ssh_strict_modes' => 'RHEL-09-255160',
            'ssh_compression' => 'RHEL-09-255130',
            'ssh_ciphers' => 'RHEL-09-255065',
            'ssh_macs' => 'RHEL-09-255075',
            'sysctl_icmp_redirect_ignore_v4' => 'RHEL-09-253015',
            'sysctl_icmp_redirect_accept_v4' => 'RHEL-09-253040',
            'sysctl_icmp_broadcast' => 'RHEL-09-253055',
            'sysctl_icmp_bogus' => 'RHEL-09-253060',
            'sysctl_icmp_redirect_send' => 'RHEL-09-253065',
            'sysctl_icmp_redirect_default' => 'RHEL-09-253070',
            'sysctl_icmp_redirect_ignore_v6' => 'RHEL-09-254015',
            'sysctl_icmp_redirect_accept_v6' => 'RHEL-09-254035',
            'audit_package' => 'RHEL-09-653010',
            'audit_service_enabled' => 'RHEL-09-653015',
            'audit_storage_full' => 'RHEL-09-653025',
            'audit_storage_error' => 'RHEL-09-653020',
            'audit_flush' => 'RHEL-09-653095',
            'selinux_targeted' => 'RHEL-09-431015',
            'sudo_reauth' => 'RHEL-09-432015',
            'sudo_password' => 'RHEL-09-611085',
            'sudo_invoking_user_pw' => 'RHEL-09-432020',
            'sudo_installed' => 'RHEL-09-432010',
            'sudo_bypass' => 'RHEL-09-611145',
            'pwquality_retries_system_auth' => 'RHEL-09-611010',
            'pwquality_enabled_password_auth' => 'RHEL-09-611040',
            'pwquality_enabled_system_auth' => 'RHEL-09-611045',
            'faillock_system_auth' => 'RHEL-09-611030',
            'faillock_password_auth' => 'RHEL-09-611035',
            'password_min_lifetime_logindefs' => 'RHEL-09-611075',
            'password_max_lifetime_logindefs' => 'RHEL-09-411010',
            'password_min_length' => 'RHEL-09-611090',
            'password_lowercase' => 'RHEL-09-611065',
            'password_uppercase' => 'RHEL-09-611110',
            'password_numeric' => 'RHEL-09-611070',
            'password_special' => 'RHEL-09-611100',
            'password_blank_null' => 'RHEL-09-611155',
        ],
        'red_hat_enterprise_linux_8' => [
            'ssh_permit_root_login' => 'RHEL-08-010550',
            'ssh_client_alive_count' => 'RHEL-08-010200',
            'ssh_client_alive_interval' => 'RHEL-08-010201',
            'ssh_banner' => 'RHEL-08-010040',
            'ssh_gssapi' => 'RHEL-08-010522',
            'ssh_kerberos' => 'RHEL-08-010521',
            'ssh_strict_modes' => 'RHEL-08-010500',
            'ssh_macs' => 'RHEL-08-010290',
            'sysctl_icmp_redirect_accept_v4' => 'RHEL-08-040209',
            'sysctl_icmp_broadcast' => 'RHEL-08-040230',
            'sysctl_icmp_redirect_send' => 'RHEL-08-040220',
            'sysctl_icmp_redirect_default' => 'RHEL-08-040270',
            'sysctl_icmp_redirect_ignore_v6' => 'RHEL-08-040280',
            'sysctl_icmp_redirect_accept_v6' => 'RHEL-08-040210',
            'audit_package' => 'RHEL-08-030180',
            'audit_storage_full' => 'RHEL-08-030060',
            'selinux_targeted' => 'RHEL-08-010450',
            'sudo_password' => 'RHEL-08-010380',
            'sudo_invoking_user_pw' => 'RHEL-08-010383',
            'sudo_bypass' => 'RHEL-08-010385',
            'pwquality_enabled_password_auth' => 'RHEL-08-020100',
            'pwquality_enabled_system_auth' => 'RHEL-08-020101',
            'faillock_system_auth' => 'RHEL-08-020025',
            'faillock_password_auth' => 'RHEL-08-020026',
            'password_uppercase' => 'RHEL-08-020110',
            'password_numeric' => 'RHEL-08-020130',
            'password_blank_null' => 'RHEL-08-010121',
        ],
    ];

    private static array $fileCache = [];

    public static function rules(array $context): array
    {
        $slug = self::resolveSlug($context);
        if ($slug === null) {
            return [];
        }

        $versions = self::RULE_VERSIONS[$slug] ?? [];
        if ($versions === []) {
            return [];
        }

        $rules = [];
        foreach (self::conceptDefinitions() as $key => $definition) {
            if (! isset($versions[$key])) {
                continue;
            }

            $ruleVersion = $versions[$key];
            $text = self::stigText($slug, $ruleVersion);

            $rules[] = [
                'id' => 'stig-'.$key,
                'ruleset' => 'stig',
                'category' => $definition['category'],
                'title' => $text['title'],
                'description' => $text['description'],
                'severity' => $text['severity'],
                'remediation' => $text['remediation'],
                'reference_url' => $text['reference_url'],
                'stig_rule_version' => $ruleVersion,
                'check' => $definition['check'],
            ];
        }

        return $rules;
    }

    public static function resolveSlug(array $context): ?string
    {
        if (! empty($context['stig_slug'])) {
            return $context['stig_slug'];
        }

        $id = strtolower($context['os_id'] ?? '');
        $majorVersion = explode('.', (string) ($context['os_version_id'] ?? ''))[0] ?? '';

        return match (true) {
            $id === 'almalinux' => 'cloudlinux_almalinux_os_9',
            $id === 'ol' => match ($majorVersion) {
                '7' => 'oracle_linux_7',
                '8' => 'oracle_linux_8',
                default => 'oracle_linux_9',
            },
            in_array($id, ['rhel', 'redhatenterpriseserver', 'centos', 'rocky', 'fedora'], true) => $majorVersion === '8' ? 'red_hat_enterprise_linux_8' : 'red_hat_enterprise_linux_9',
            $id === 'ubuntu' => match ($majorVersion) {
                '20' => 'canonical_ubuntu_2004_lts',
                '24' => 'canonical_ubuntu_2404_lts',
                default => 'canonical_ubuntu_2204_lts',
            },
            in_array($id, ['sles', 'sled'], true) => $majorVersion === '12' ? 'suse_linux_enterprise_server_12' : 'suse_linux_enterprise_server_15',
            default => null,
        };
    }

    private static function conceptDefinitions(): array
    {
        return [
            'ssh_permit_root_login' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'permitrootlogin', ['no'])],
            'ssh_client_alive_count' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshNumeric($f, 'clientalivecountmax', fn (?int $v) => $v !== null && $v <= 1)],
            'ssh_client_alive_interval' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshNumeric($f, 'clientaliveinterval', fn (?int $v) => $v !== null && $v > 0 && $v <= 600)],
            'ssh_banner' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshSet($f, 'banner')],
            'ssh_empty_password' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'permitemptypasswords', ['no'])],
            'ssh_pam' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'usepam', ['yes'])],
            'ssh_x11forwarding' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'x11forwarding', ['no'])],
            'ssh_gssapi' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'gssapiauthentication', ['no'])],
            'ssh_kerberos' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'kerberosauthentication', ['no'])],
            'ssh_rhosts' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'ignorerhosts', ['yes'])],
            'ssh_known_hosts' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'hostbasedauthentication', ['no'])],
            'ssh_strict_modes' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'strictmodes', ['yes'])],
            'ssh_compression' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshEquals($f, 'compression', ['no', 'delayed'])],
            'ssh_ciphers' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshNoWeakAlgorithms($f, 'ciphers', self::WEAK_CIPHERS)],
            'ssh_macs' => ['category' => 'SSH Hardening', 'check' => fn (array $f) => self::sshNoWeakAlgorithms($f, 'macs', self::WEAK_MACS)],

            'sysctl_icmp_redirect_ignore_v4' => ['category' => 'Kernel Hardening', 'check' => fn (array $f) => self::sysctlEquals($f, 'net.ipv4.conf.default.accept_redirects', '0')],
            'sysctl_icmp_redirect_accept_v4' => ['category' => 'Kernel Hardening', 'check' => fn (array $f) => self::sysctlEquals($f, 'net.ipv4.conf.all.accept_redirects', '0')],
            'sysctl_icmp_broadcast' => ['category' => 'Kernel Hardening', 'check' => fn (array $f) => self::sysctlEquals($f, 'net.ipv4.icmp_echo_ignore_broadcasts', '1')],
            'sysctl_icmp_bogus' => ['category' => 'Kernel Hardening', 'check' => fn (array $f) => self::sysctlEquals($f, 'net.ipv4.icmp_ignore_bogus_error_responses', '1')],
            'sysctl_icmp_redirect_send' => ['category' => 'Kernel Hardening', 'check' => fn (array $f) => self::sysctlEquals($f, 'net.ipv4.conf.all.send_redirects', '0')],
            'sysctl_icmp_redirect_default' => ['category' => 'Kernel Hardening', 'check' => fn (array $f) => self::sysctlEquals($f, 'net.ipv4.conf.default.send_redirects', '0')],
            'sysctl_icmp_redirect_ignore_v6' => ['category' => 'Kernel Hardening', 'check' => fn (array $f) => self::sysctlEquals($f, 'net.ipv6.conf.default.accept_redirects', '0')],
            'sysctl_icmp_redirect_accept_v6' => ['category' => 'Kernel Hardening', 'check' => fn (array $f) => self::sysctlEquals($f, 'net.ipv6.conf.all.accept_redirects', '0')],

            'audit_package' => ['category' => 'Auditing', 'check' => fn (array $f) => self::boolResult(self::packageInstalled($f, 'audit'), ['package' => 'audit'])],
            'audit_service_enabled' => ['category' => 'Auditing', 'check' => fn (array $f) => self::boolResult(self::unitActive($f, 'auditd.service'), ['unit' => self::systemdUnit($f, 'auditd.service')])],
            'audit_storage_full' => ['category' => 'Auditing', 'check' => fn (array $f) => self::auditdConfigured($f, 'admin_space_left_action')],
            'audit_storage_error' => ['category' => 'Auditing', 'check' => fn (array $f) => self::auditdConfigured($f, 'disk_error_action')],
            'audit_flush' => ['category' => 'Auditing', 'check' => fn (array $f) => self::boolResult(self::auditdValue($f, 'freq') !== null, ['freq' => self::auditdValue($f, 'freq')])],

            'selinux_targeted' => ['category' => 'Mandatory Access Control', 'check' => fn (array $f) => self::boolResult(strtolower((string) ($f['selinux']['type'] ?? '')) === 'targeted', ['type' => $f['selinux']['type'] ?? null])],

            'sudo_reauth' => ['category' => 'Privilege Escalation', 'check' => fn (array $f) => self::boolResult(! self::sudoersContains($f, '!authenticate'), ['directive' => '!authenticate'])],
            'sudo_password' => ['category' => 'Privilege Escalation', 'check' => fn (array $f) => self::boolResult(! self::sudoersContains($f, 'nopasswd'), ['directive' => 'NOPASSWD'])],
            'sudo_invoking_user_pw' => ['category' => 'Privilege Escalation', 'check' => fn (array $f) => self::boolResult(! self::sudoersContains($f, 'rootpw') && ! self::sudoersContains($f, 'targetpw') && ! self::sudoersContains($f, 'runaspw'), ['directives' => ['rootpw', 'targetpw', 'runaspw']])],
            'sudo_installed' => ['category' => 'Privilege Escalation', 'check' => fn (array $f) => self::boolResult(self::packageInstalled($f, 'sudo'), ['package' => 'sudo'])],
            'sudo_bypass' => ['category' => 'Privilege Escalation', 'check' => fn (array $f) => self::boolResult(! self::pamContains($f, 'sudo', 'pam_permit.so'), ['module' => 'pam_permit.so'])],

            'pwquality_retries_system_auth' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::pwqualityArg($f, ['system_auth'], 'retry', fn (?int $v) => $v !== null && $v > 0 && $v <= 3)],
            'pwquality_enabled_password_auth' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::boolResult(self::pamModulePresent($f, ['password_auth'], 'pam_pwquality.so'), ['module' => 'pam_pwquality.so', 'file' => 'password-auth'])],
            'pwquality_enabled_system_auth' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::boolResult(self::pamModulePresent($f, ['system_auth'], 'pam_pwquality.so'), ['module' => 'pam_pwquality.so', 'file' => 'system-auth'])],
            'faillock_system_auth' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::boolResult(self::pamModulePresent($f, ['system_auth'], 'pam_faillock.so'), ['module' => 'pam_faillock.so', 'file' => 'system-auth'])],
            'faillock_password_auth' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::boolResult(self::pamModulePresent($f, ['password_auth'], 'pam_faillock.so'), ['module' => 'pam_faillock.so', 'file' => 'password-auth'])],

            'password_min_lifetime_logindefs' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::loginDefsNumeric($f, 'PASS_MIN_DAYS', fn (?int $v) => $v !== null && $v >= 1)],
            'password_max_lifetime_logindefs' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::loginDefsNumeric($f, 'PASS_MAX_DAYS', fn (?int $v) => $v !== null && $v > 0 && $v <= 60)],
            'password_min_length' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::pwqualityArg($f, ['system_auth', 'password_auth'], 'minlen', fn (?int $v) => $v !== null && $v >= 15)],
            'password_lowercase' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::pwqualityArg($f, ['system_auth', 'password_auth'], 'lcredit', fn (?int $v) => $v !== null && $v <= -1)],
            'password_uppercase' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::pwqualityArg($f, ['system_auth', 'password_auth'], 'ucredit', fn (?int $v) => $v !== null && $v <= -1)],
            'password_numeric' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::pwqualityArg($f, ['system_auth', 'password_auth'], 'dcredit', fn (?int $v) => $v !== null && $v <= -1)],
            'password_special' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::pwqualityArg($f, ['system_auth', 'password_auth'], 'ocredit', fn (?int $v) => $v !== null && $v <= -1)],
            'password_blank_null' => ['category' => 'Password Policy', 'check' => fn (array $f) => self::boolResult(! self::pamContains($f, 'system_auth', 'nullok') && ! self::pamContains($f, 'password_auth', 'nullok'), ['directive' => 'nullok'])],
        ];
    }

    private static function boolResult(bool $pass, array $evidence): array
    {
        return ['status' => $pass ? 'pass' : 'fail', 'evidence' => $evidence, 'matched_path' => null];
    }

    private static function sshEquals(array $facts, string $directive, array $passValues): array
    {
        $value = self::sshDirective($facts, $directive);
        $status = $value !== null && in_array(strtolower(trim($value)), array_map('strtolower', $passValues), true) ? 'pass' : 'fail';

        return ['status' => $status, 'evidence' => ['directive' => $directive, 'found' => $value], 'matched_path' => 'etc/ssh/sshd_config'];
    }

    private static function sshSet(array $facts, string $directive): array
    {
        $value = self::sshDirective($facts, $directive);
        $status = $value !== null && ! in_array(strtolower($value), ['', 'none'], true) ? 'pass' : 'fail';

        return ['status' => $status, 'evidence' => ['directive' => $directive, 'found' => $value], 'matched_path' => 'etc/ssh/sshd_config'];
    }

    private static function sshNumeric(array $facts, string $directive, \Closure $isCompliant): array
    {
        $value = self::toInt(self::sshDirective($facts, $directive));

        return ['status' => $isCompliant($value) ? 'pass' : 'fail', 'evidence' => ['directive' => $directive, 'found' => $value], 'matched_path' => 'etc/ssh/sshd_config'];
    }

    private static function sshNoWeakAlgorithms(array $facts, string $directive, array $weakList): array
    {
        $value = self::sshDirective($facts, $directive);
        $weak = [];
        if ($value !== null) {
            foreach ($weakList as $algorithm) {
                if (str_contains(strtolower($value), $algorithm)) {
                    $weak[] = $algorithm;
                }
            }
        }

        return ['status' => $weak === [] ? 'pass' : 'fail', 'evidence' => ['directive' => $directive, 'found' => $value, 'weak' => $weak], 'matched_path' => 'etc/ssh/sshd_config'];
    }

    private static function sysctlEquals(array $facts, string $param, string $expected): array
    {
        $value = self::sysctlValue($facts, $param);

        return ['status' => $value === $expected ? 'pass' : 'fail', 'evidence' => ['param' => $param, 'found' => $value, 'expected' => $expected], 'matched_path' => null];
    }

    private static function auditdConfigured(array $facts, string $key): array
    {
        $value = self::auditdValue($facts, $key);
        $status = $value !== null && strtolower($value) !== 'ignore' ? 'pass' : 'fail';

        return ['status' => $status, 'evidence' => [$key => $value], 'matched_path' => 'etc/audit/auditd.conf'];
    }

    private static function loginDefsNumeric(array $facts, string $key, \Closure $isCompliant): array
    {
        $value = self::toInt(self::loginDefsValue($facts, $key));

        return ['status' => $isCompliant($value) ? 'pass' : 'fail', 'evidence' => [$key => $value], 'matched_path' => 'etc/login.defs'];
    }

    private static function pwqualityArg(array $facts, array $files, string $arg, \Closure $isCompliant): array
    {
        $value = self::pamModuleArgValue($facts, $files, 'pam_pwquality.so', $arg);

        return ['status' => $isCompliant(self::toInt($value)) ? 'pass' : 'fail', 'evidence' => ['arg' => $arg, 'found' => $value], 'matched_path' => 'etc/pam.d/system-auth'];
    }

    private static function loadStigFile(string $slug): array
    {
        if (isset(self::$fileCache[$slug])) {
            return self::$fileCache[$slug];
        }

        $path = base_path("json/stig/{$slug}.json");
        if (! is_file($path)) {
            throw new \RuntimeException("STIG source file not found: {$slug}.json");
        }

        $data = json_decode(file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("STIG source file is not valid JSON: {$slug}.json");
        }

        $byVersion = [];
        foreach ($data['groups'] ?? [] as $group) {
            $byVersion[$group['ruleVersion']] = $group;
        }

        return self::$fileCache[$slug] = $byVersion;
    }

    private static function stigText(string $slug, string $ruleVersion): array
    {
        $byVersion = self::loadStigFile($slug);

        if (! isset($byVersion[$ruleVersion])) {
            throw new \RuntimeException("STIG rule version '{$ruleVersion}' not found in {$slug}.json");
        }

        $group = $byVersion[$ruleVersion];

        return [
            'title' => $group['ruleTitle'],
            'description' => $group['ruleVulnDiscussion'],
            'severity' => self::mapSeverity($group['ruleSeverity']),
            'remediation' => $group['ruleFixText'],
            'reference_url' => null,
        ];
    }

    private static function mapSeverity(string $stigSeverity): string
    {
        return match (strtolower($stigSeverity)) {
            'low' => 'LOW',
            'high' => 'HIGH',
            'critical', 'catastrophic' => 'CRITICAL',
            default => 'MEDIUM',
        };
    }
}
