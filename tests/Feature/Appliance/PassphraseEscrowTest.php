<?php

/**
 * Passphrase storage / reboot-survival design.
 *
 * The svault keyring is non-persistent across reboots, so the LUKS passphrase
 * is escrowed at install time (installer.sh step 5b/6) under one of three
 * policies and recovered at boot by sysadmin/resolve-passphrase.sh:
 *   tpm     — sealed to a TPM (no PCR binding); needs hardware, not covered here.
 *   relaxed — AES-256 on disk, key derived from machine identity (DEFAULT).
 *   vault   — HashiCorp Vault over the network; needs a server, not covered here.
 *
 * These tests cover what's verifiable without a TPM or a Vault server:
 *   - the scripts ship, are executable, and pass `bash -n`
 *   - the `relaxed` backend round-trips (escrow encrypt → resolver decrypt)
 *   - svaultKey.service is a system unit that runs as UID 1000 before the stack
 *   - the sudoers fragment scopes UID 1000 to exactly the cryptsetup/mount verbs
 *   - installer.sh wires the policy step, escrow, and service install
 */

use Illuminate\Support\Facades\Process;

function resolverPath(): string
{
    return base_path('sysadmin/resolve-passphrase.sh');
}

it('ships the escrow scripts executable and syntactically valid', function () {
    foreach (['sysadmin/resolve-passphrase.sh', 'sysadmin/execStart.sh', 'sysadmin/init.sh'] as $rel) {
        $path = base_path($rel);
        expect(is_file($path))->toBeTrue("$rel missing")
            ->and(is_executable($path))->toBeTrue("$rel not executable");

        $syntax = Process::run('bash -n '.escapeshellarg($path));
        expect($syntax->successful())->toBeTrue("$rel failed bash -n: ".$syntax->errorOutput());
    }
});

it('round-trips a passphrase through the relaxed (machine-derived) backend', function () {
    if (! is_executable('/usr/bin/openssl') && trim(shell_exec('command -v openssl') ?? '') === '') {
        $this->markTestSkipped('openssl not available');
    }

    $keydir = sys_get_temp_dir().'/svaultkey-'.uniqid();
    $secret = 'correct horse battery staple';

    // Reproduce installer.sh escrow_passphrase() for the relaxed policy, then
    // recover via the real resolver. The derive material is read from the same
    // machine files the resolver uses, so encrypt and decrypt always agree.
    $setupFile = $keydir.'.setup.sh';
    @mkdir($keydir, 0700, true);
    file_put_contents($setupFile, implode("\n", [
        'set -euo pipefail',
        'keydir="$1"; secret="$2"',
        'mkdir -p "$keydir"',
        'keymat="machine-id:$(cat /etc/machine-id)"',
        'ptmp="$(mktemp)"; printf \'%s\' "$secret" > "$ptmp"',
        'printf \'%s\' "$keymat" | openssl enc -aes-256-cbc -pbkdf2 -salt -in "$ptmp" -out "$keydir/passphrase.enc" -pass stdin',
        'rm -f "$ptmp"',
        'printf \'POLICY_VERSION=1\nPOLICY=relaxed\nENC_FILE=passphrase.enc\nKDF=machine-id\n\' > "$keydir/policy"',
    ])."\n");

    $escrow = Process::run(['bash', $setupFile, $keydir, $secret]);
    expect($escrow->successful())->toBeTrue('escrow failed: '.$escrow->errorOutput());

    $resolve = Process::env(['SVAULT_KEYDIR' => $keydir])
        ->run('bash '.escapeshellarg(resolverPath()));

    expect($resolve->successful())->toBeTrue('resolver failed: '.$resolve->errorOutput())
        ->and($resolve->output())->toBe($secret);

    // Cleanup.
    Process::run('rm -rf '.escapeshellarg($keydir).' '.escapeshellarg($setupFile));
});

it('fails closed when no policy file is present', function () {
    $keydir = sys_get_temp_dir().'/svaultkey-empty-'.uniqid();
    @mkdir($keydir, 0700, true);

    $resolve = Process::env(['SVAULT_KEYDIR' => $keydir])
        ->run('bash '.escapeshellarg(resolverPath()));

    expect($resolve->successful())->toBeFalse()
        ->and($resolve->errorOutput())->toContain('no policy file');

    Process::run('rm -rf '.escapeshellarg($keydir));
});

it('svaultKey.service is a system oneshot that runs as UID 1000 after the stack', function () {
    $unit = file_get_contents(base_path('sysadmin/svaultKey.service'));

    expect($unit)
        ->toContain('Type=oneshot')
        ->toContain('User=1000')
        // Ordered After=/PartOf= sos-vault.service (not Before=): the keys must
        // land in @u *after* the container pins that uid keyring, else they GC
        // to an empty keyring at boot. Regressing to Before= re-opens that race.
        ->toContain('After=network-online.target sos-vault.service')
        ->toContain('PartOf=sos-vault.service')
        ->not->toContain('Before=sos-vault.service')
        ->toContain('KeyringMode=shared')
        // execStart is reached via SOS_VAULT_DIR from /etc/default/sos-vault.
        ->toContain('sysadmin/execStart.sh')
        // The dead, developer-specific /home path must be gone.
        ->not->toContain('/home/jlrueda')
        ->not->toContain('systemd/user');
});

it('sos-vault-svaultkey sudoers fragment scopes UID 1000 to the LUKS verbs only', function () {
    $frag = base_path('sysadmin/sudoers.d/sos-vault-svaultkey');
    $body = file_get_contents($frag);

    expect($body)
        ->toContain('#1000 ALL=(root) NOPASSWD:')
        // Literal paths only — sudo-rs (Ubuntu 26.04) rejects wildcards in
        // command arguments, so the key-file / device / mountpoint must be
        // concrete. The shipped default key dir is rewritten to the real
        // SVAULT_KEYDIR by install_one_sudoers.
        ->toContain('--key-file=/var/lib/sos-vault/svaultkey/.keyfile')
        ->toContain('luksOpen /var/lib/sos-vault/svaultkey/svault.key svault')
        ->toContain('/bin/mount -o ro /dev/mapper/svault /var/lib/sos-vault/svaultkey/m')
        ->toContain('luksClose svault')
        // No wildcards anywhere in the Cmnd_Alias.
        ->not->toContain('--key-file=*')
        ->not->toContain('luksOpen * svault');

    // If visudo exists, the fragment must parse.
    foreach (['/usr/sbin/visudo', '/usr/bin/visudo'] as $bin) {
        if (is_executable($bin)) {
            $result = Process::run([$bin, '-cf', $frag]);
            expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

            return;
        }
    }
    $this->markTestSkipped('visudo not available on this host');
});

it('resolve-passphrase + execStart fall back to /etc/default/sos-vault when SVAULT_KEYDIR is unset', function () {
    // Run by hand (no EnvironmentFile), both scripts source /etc/default/sos-vault
    // so the operator need not export SVAULT_KEYDIR. This is what bit a manual
    // recovery run: it fell back to $HOME/.config/svaultKey and reported
    // "no policy file".
    foreach (['sysadmin/resolve-passphrase.sh', 'sysadmin/execStart.sh'] as $rel) {
        $body = file_get_contents(base_path($rel));
        expect($body)
            ->toContain('-r /etc/default/sos-vault')
            ->toContain('. /etc/default/sos-vault');
    }
});

it('execStart invokes the inner script via bash (read-only, exec-bit independent)', function () {
    $body = file_get_contents(base_path('sysadmin/execStart.sh'));

    // Direct exec of a file on the ro LUKS mount fails closed if the exec bit
    // or ownership is off; bash needs only read perm and runs as the same uid.
    expect($body)->toContain('/bin/bash "$dir/m/script.sh"');
});

it('the app-stack systemd unit defaults to /opt/sos-vault with no developer path', function () {
    $unit = file_get_contents(base_path('sysadmin/sos-vault.service'));

    expect($unit)
        ->toContain('Environment=SOS_VAULT_DIR=/opt/sos-vault')
        ->toContain('EnvironmentFile=-/etc/default/sos-vault')
        // The dead developer-specific /home path must be gone.
        ->not->toContain('/home/jlrueda');
});

it('installer wires the policy step, escrow, and key-service install', function () {
    $body = file_get_contents(base_path('sysadmin/installer.sh'));

    expect($body)
        ->toContain('step_05b_choose_keystore_policy')
        ->toContain('escrow_passphrase')
        ->toContain('load_keyring_now')
        // TPM is auto-selected only when actually usable.
        ->toContain('tpm_usable')
        ->toContain('/dev/tpmrm0')
        // The Relaxed/Strong menu the operator sees with no TPM.
        ->toContain('Relaxed (default)')
        ->toContain('Strong')
        // Both units + the key dir land in /etc/default/sos-vault.
        ->toContain('SVAULT_KEYDIR=')
        ->toContain('svaultKey.service');
});

it('escrow runs only behind --dry-run when walking the installer', function () {
    $result = Process::env(['SOS_VAULT_DIR' => base_path()])
        ->run('bash '.escapeshellarg(base_path('sysadmin/installer.sh')).' --dry-run');

    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output())
        ->and($result->output())->toContain('Step 5b/15')
        ->and($result->output())->toContain('escrowing passphrase')
        // policy write must be gated.
        ->and($result->output())->toMatch('/\[dry-run\].*POLICY=relaxed/');
});
