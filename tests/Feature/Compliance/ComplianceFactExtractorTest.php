<?php

use App\Models\User;
use App\Models\Vault;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\Compliance\ComplianceFactExtractor;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * ComplianceFactExtractor — built on the lightweight vaultsDisabled=TRUE
 * harness (real files on a plain temp directory, no LUKS mount), same
 * convention as DataToolsDockerTest.
 */

function complianceTestEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-compliance-'.bin2hex(random_bytes(4));
    mkdir($root, 0o755, true);
    Config::set('filesystems.disks.vault.root', $root);
    Config::set('app.vaultsDisabled', 'TRUE');

    $user = User::factory()->create();
    $user->syncRoles(['admin']);

    $vname = 'tv'.bin2hex(random_bytes(3));
    $mountp = "{$root}/{$vname}";
    mkdir($mountp, 0o755, true);

    $vault = Vault::create([
        'user_vault' => $vname,
        'device' => "{$root}/.{$vname}.img",
        'header_file' => "{$root}/.headers/.{$vname}.data",
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $user->id,
        'group' => $user->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'description' => 'test vault',
        'current_size' => 100 * 1024 * 1024,
        'plan_size' => 100 * 1024 * 1024,
    ]);

    return ['user' => $user, 'vault' => $vault, 'mount' => $mountp, 'root' => $root];
}

function complianceDataTools(array $env, string $dirName): DataTools
{
    $vtools = new VaultTools($env['user'], $env['vault']->id);
    $mount = $vtools->getMountPoint();

    $src = "{$mount}/{$dirName}";
    if (! is_dir($src)) {
        mkdir($src, 0o755, true);
    }

    $dirId = fileinode($src);

    return new DataTools($vtools, $env['vault']->id, $dirId);
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-compliance-')) {
        exec('/bin/sudo /bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
        is_dir($root) && exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
    Config::set('app.vaultsDisabled', 'TRUE');
});

it('extracts ssh directives from sshd_config, first-wins, lowercased keys', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-ssh";
    mkdir("{$src}/etc/ssh", 0o755, true);
    file_put_contents("{$src}/etc/ssh/sshd_config", <<<'TXT'
# comment line
PermitRootLogin no
PermitRootLogin yes
X11Forwarding no
MaxAuthTries 4
TXT);

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-ssh'));

    expect($facts['ssh']['directives'])->toBe([
        'permitrootlogin' => 'no',
        'x11forwarding' => 'no',
        'maxauthtries' => '4',
    ]);
});

it('merges sshd_config.d drop-ins ahead of the main sshd_config file', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-sshdropin";
    mkdir("{$src}/etc/ssh/sshd_config.d", 0o755, true);
    file_put_contents("{$src}/etc/ssh/sshd_config.d/50-cloud-init.conf", "PermitRootLogin prohibit-password\n");
    file_put_contents("{$src}/etc/ssh/sshd_config", "PermitRootLogin yes\nX11Forwarding no\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-sshdropin'));

    expect($facts['ssh']['directives']['permitrootlogin'])->toBe('prohibit-password')
        ->and($facts['ssh']['directives']['x11forwarding'])->toBe('no');
});

it('extracts pam facts as filtered raw lines per file, omitting files that are absent', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-pam";
    mkdir("{$src}/etc/pam.d", 0o755, true);
    file_put_contents("{$src}/etc/pam.d/system-auth", "# comment\n\nauth required pam_faillock.so\npassword requisite pam_pwquality.so minlen=14\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-pam'));

    expect($facts['pam'])->toHaveKey('system_auth')
        ->and($facts['pam']['system_auth'])->toBe([
            'auth required pam_faillock.so',
            'password requisite pam_pwquality.so minlen=14',
        ])
        ->and($facts['pam'])->not->toHaveKey('password_auth')
        ->and($facts['pam'])->not->toHaveKey('sudo');
});

it('extracts sudoers raw_lines, and omits the sudoers key entirely when the file is absent', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-sudoers";
    mkdir("{$src}/etc", 0o755, true);
    file_put_contents("{$src}/etc/sudoers", "Defaults secure_path=/sbin:/bin\n%wheel ALL=(ALL) NOPASSWD: ALL\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-sudoers'));

    expect($facts['sudoers']['raw_lines'])->toBe([
        'Defaults secure_path=/sbin:/bin',
        '%wheel ALL=(ALL) NOPASSWD: ALL',
    ]);

    $env2 = complianceTestEnv();
    $srcNoSudoers = "{$env2['mount']}/sosreport-host0-CASE-2026-01-01-nosudoers";
    mkdir($srcNoSudoers, 0o755, true);
    file_put_contents("{$srcNoSudoers}/uname", "Linux prod-host\n");

    $facts2 = (new ComplianceFactExtractor)->extract(complianceDataTools($env2, 'sosreport-host0-CASE-2026-01-01-nosudoers'));

    expect($facts2)->not->toHaveKey('sudoers');
});

it('extracts selinux config_mode and type', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-selinux";
    mkdir("{$src}/etc/selinux", 0o755, true);
    file_put_contents("{$src}/etc/selinux/config", "# comment\nSELINUX=enforcing\nSELINUXTYPE=targeted\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-selinux'));

    expect($facts['selinux'])->toBe(['config_mode' => 'enforcing', 'type' => 'targeted']);
});

it('extracts auditd config and watched paths, combining auditd.conf and audit.rules', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-auditd";
    mkdir("{$src}/etc/audit", 0o755, true);
    file_put_contents("{$src}/etc/audit/auditd.conf", "log_file = /var/log/audit/audit.log\nadmin_space_left_action = halt\n");
    file_put_contents("{$src}/etc/audit/audit.rules", "-w /usr/bin/dockerd -p x -k docker\n-w /var/lib/docker -p wa -k docker\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-auditd'));

    expect($facts['auditd']['config'])->toBe([
        'log_file' => '/var/log/audit/audit.log',
        'admin_space_left_action' => 'halt',
    ])
        ->and($facts['auditd']['watched_paths'])->toBe(['/usr/bin/dockerd', '/var/lib/docker']);
});

it('extracts login_defs directives', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-logindefs";
    mkdir("{$src}/etc", 0o755, true);
    file_put_contents("{$src}/etc/login.defs", "PASS_MAX_DAYS\t60\nPASS_MIN_DAYS\t1\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-logindefs'));

    expect($facts['login_defs']['directives'])->toBe([
        'pass_max_days' => '60',
        'pass_min_days' => '1',
    ]);
});

it('degrades gracefully to empty/absent facts when every raw config file is missing', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-empty";
    mkdir($src, 0o755, true);
    file_put_contents("{$src}/uname", "Linux prod-host\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-empty'));

    expect($facts['ssh'])->toBe(['directives' => []])
        ->and($facts['pam'])->toBe([])
        ->and($facts)->not->toHaveKey('sudoers')
        ->and($facts)->not->toHaveKey('selinux')
        ->and($facts)->not->toHaveKey('auditd')
        ->and($facts)->not->toHaveKey('login_defs')
        ->and($facts['firewall'])->toBeNull()
        ->and($facts['sysctl'])->toBeNull()
        ->and($facts['systemd'])->toBeNull()
        ->and($facts['packages'])->toBeNull()
        ->and($facts['docker'])->toBeNull()
        ->and($facts['network'])->toBe([]);
});

it('derives the os family from etc/os-release', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-os";
    mkdir("{$src}/etc", 0o755, true);
    file_put_contents("{$src}/etc/os-release", "NAME=\"Red Hat Enterprise Linux\"\nID=\"rhel\"\nVERSION_ID=\"9.4\"\nPRETTY_NAME=\"Red Hat Enterprise Linux 9.4\"\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-os'));

    expect($facts['os'])->toBe([
        'family' => 'rhel',
        'id' => 'rhel',
        'version_id' => '9.4',
        'name' => 'Red Hat Enterprise Linux',
        'pretty_name' => 'Red Hat Enterprise Linux 9.4',
    ]);
});

it('never throws when the report directory is entirely bare', function () {
    $env = complianceTestEnv();
    $src = "{$env['mount']}/sosreport-host0-CASE-2026-01-01-bare";
    mkdir($src, 0o755, true);
    file_put_contents("{$src}/uname", "Linux prod-host\n");

    $facts = (new ComplianceFactExtractor)->extract(complianceDataTools($env, 'sosreport-host0-CASE-2026-01-01-bare'));

    expect($facts)->toBeArray()
        ->and($facts['os']['family'])->toBe('unknown');
});
