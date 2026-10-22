<?php

/**
 * Arbitrary app-service UID support.
 *
 * The app runs as one uid on BOTH sides of the kernel-keyring coupling: the
 * host key service (svaultKey.service) and the container process (www-data is
 * remapped at container start). The published GHCR image bakes www-data at
 * 1000, but the installer provisions a dedicated `sosvault` system user whose
 * uid is whatever the host assigns — so nothing may hardcode 1000 on the host
 * side. These tests verify the uid is threaded end-to-end and that the two
 * runtime rewrites (sudoers principal, container www-data remap) actually work
 * for a non-1000 uid.
 */

use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

it('provisions a locked system app user and threads APP_UID through the installer', function () {
    $body = file_get_contents(base_path('sysadmin/installer.sh'));

    expect($body)
        ->toContain('step_02b_provision_app_user')
        ->toContain('SOS_VAULT_APP_USER')
        // a locked, no-login system account.
        ->toContain('useradd --system')
        ->toContain('/usr/sbin/nologin')
        // resolved from the real user, not assumed.
        ->toContain('APP_UID="$(id -u "$SOS_VAULT_APP_USER")"')
        ->toContain('APP_GID="$(id -g "$SOS_VAULT_APP_USER")"');

    // main() runs it before docker so every later chown/sudo sees the uid.
    expect($body)->toMatch('/step_02_check_hardware\s*\n\s*step_02b_provision_app_user\s*\n\s*step_03_install_docker/');
});

it('leaves no hardcoded chown 1000 on the host side', function () {
    $body = file_get_contents(base_path('sysadmin/installer.sh'));

    // Every ownership op must use the resolved app uid/gid.
    expect($body)
        ->not->toContain('chown 1000:1000')
        ->not->toContain('chown -R 1000:1000')
        ->toContain('chown "${APP_UID}:${APP_GID}"')
        ->toContain('WWWUSER=${APP_UID}')
        ->toContain('WWWGROUP=${APP_GID}');

    // init.sh chowns the device/mount to the passed-in uid, not a literal.
    $init = file_get_contents(base_path('sysadmin/init.sh'));
    expect($init)
        ->toContain('uid="${SVAULT_UID:-1000}"')
        ->toContain('chown $uid:$gid $device')
        ->not->toContain('chown 1000:1000')
        // The inner LUKS fs (script.sh + .data*) is handed to the app uid before
        // unmount — init runs as root in production, so the files would
        // otherwise be root-owned and the app-uid boot service hits EACCES.
        ->toContain('chown -R $uid:$gid $mountp')
        // The generated script.sh shebang is line 1 (no leading blank line),
        // so a direct exec doesn't fall through to an ENOEXEC sh fallback.
        ->toContain("script.sh << EOF\n#!/bin/bash");
});

it('pins the key service User= and sudoers principal to the app uid on install', function () {
    $body = file_get_contents(base_path('sysadmin/installer.sh'));

    expect($body)
        // unit User=/Group= rewritten (systemd cannot env-expand User=).
        ->toContain('s/^User=.*/User=${APP_UID}/')
        ->toContain('s/^Group=.*/Group=${APP_GID}/')
        // sudoers principal templated from the shipped #1000 default.
        ->toContain('s/^#1000 /#${APP_UID} /')
        // recorded for reference in the env file.
        ->toContain('APP_UID=%s');
});

it('pins the sudoers KEYDIR rewrite alongside the uid principal on install', function () {
    $body = file_get_contents(base_path('sysadmin/installer.sh'));

    // install_one_sudoers rewrites the shipped default key dir to the real
    // SVAULT_KEYDIR — sudo-rs forbids wildcards, so the literal path in
    // sos-vault-svaultkey must be templated to the install location.
    expect($body)
        ->toContain('s|/var/lib/sos-vault/svaultkey|${keydir}|g')
        ->toContain('keydir="${SVAULT_KEYDIR:-/var/lib/sos-vault/svaultkey}"');
});

it('templates the svaultkey sudoers fragment to a non-1000 uid + custom keydir and it still parses', function () {
    $frag = base_path('sysadmin/sudoers.d/sos-vault-svaultkey');

    // Reproduce install_one_sudoers' full substitution: app uid principal +
    // the default key dir rewritten to an arbitrary install location.
    $staged = Process::run('sed -e '.escapeshellarg('s/^#1000 /#4242 /')
        .' -e '.escapeshellarg('s|/var/lib/sos-vault/svaultkey|/srv/keys|g')
        .' '.escapeshellarg($frag));
    expect($staged->successful())->toBeTrue()
        ->and($staged->output())->toContain('#4242 ALL=(root) NOPASSWD:')
        ->and($staged->output())->not->toContain('#1000 ALL=')
        // device / key-file / mountpoint all rewritten to the custom keydir.
        ->and($staged->output())->toContain('--key-file=/srv/keys/.keyfile')
        ->and($staged->output())->toContain('luksOpen /srv/keys/svault.key svault')
        ->and($staged->output())->toContain('/dev/mapper/svault /srv/keys/m')
        ->and($staged->output())->not->toContain('/var/lib/sos-vault/svaultkey');

    foreach (['/usr/sbin/visudo', '/usr/bin/visudo'] as $bin) {
        if (is_executable($bin)) {
            $tmp = tempnam(sys_get_temp_dir(), 'svk');
            file_put_contents($tmp, $staged->output());
            $check = Process::run([$bin, '-cf', $tmp]);
            @unlink($tmp);
            expect($check->successful())->toBeTrue($check->errorOutput() ?: $check->output());

            return;
        }
    }
    $this->markTestSkipped('visudo not available on this host');
});

it('remaps www-data to an arbitrary uid in the container entrypoint', function () {
    $start = file_get_contents(base_path('sysadmin/container_start.sh'));

    // The remap is conditional on WWWUSER being set and different.
    expect($start)
        ->toContain('WWWUSER')
        ->toContain('/^www-data:/s/:[0-9][0-9]*:[0-9][0-9]*:/:${WWWUSER}:${WWWGROUP:-$WWWUSER}:/')
        ->toContain('/etc/passwd')
        ->toContain('/etc/group');

    // Behavioral: apply the same passwd sed to a synthetic line and confirm
    // the uid/gid actually change.
    $passwd = 'www-data:x:1000:1000:www-data:/var/www:/usr/sbin/nologin';
    $cmd = 'printf %s '.escapeshellarg($passwd)
        .' | sed -e '.escapeshellarg('/^www-data:/s/:[0-9][0-9]*:[0-9][0-9]*:/:4242:4242:/');
    $result = Process::run(['bash', '-c', $cmd]);

    expect($result->successful())->toBeTrue()
        ->and(trim($result->output()))->toBe('www-data:x:4242:4242:www-data:/var/www:/usr/sbin/nologin');
});

it('re-owns the baked .gnupg verification keyring to the remapped app uid', function () {
    $start = file_get_contents(base_path('sysadmin/container_start.sh'));

    // The license/module verification keyring (.gnupg, public keys only) is
    // baked into the image owned by the build uid 1000, mode 700. gpg cannot
    // operate on a homedir it can't own (it locks + writes the trustdb inside),
    // so when the installer provisions a non-1000 system uid the entrypoint
    // MUST hand the keyring to the remapped app uid — otherwise `gpg --verify`
    // hits EACCES on pubring.kbx and every license upload fails with
    // "not signed by the build keyring".
    expect($start)->toContain('chown -R www-data:www-data /var/www/site/.gnupg');

    // Behavioral: a 700 homedir owned by a *different* uid is unusable (the
    // failure mode), but readable+lockable once chowned to the running uid.
    if (! is_executable('/usr/bin/gpg') && ! is_executable('/usr/local/bin/gpg')) {
        return;
    }
    $home = sys_get_temp_dir().'/sosvault-gnupg-'.uniqid();
    mkdir($home, 0700, true);
    Process::run(['gpg', '--homedir', $home, '--batch', '--no-tty', '--list-keys']);
    // After chmod 700 + correct owner (us), gpg can lock/check the trustdb.
    $check = Process::run(['gpg', '--homedir', $home, '--batch', '--no-tty', '--check-trustdb']);
    expect($check->successful())->toBeTrue($check->errorOutput() ?: $check->output());

    exec('rm -rf '.escapeshellarg($home));
});

it('seeds default public assets from storage-seed into storage on boot without clobbering uploads', function () {
    $start = file_get_contents(base_path('sysadmin/container_start.sh'));

    // The deb ships default public assets read-only at storage-seed/; the
    // entrypoint copies them into the host-mounted storage/app/public on boot.
    // cp -rn (no-clobber) preserves operator uploads; placed before the storage
    // chown so the copied files get the app uid.
    expect($start)
        ->toContain('/var/www/site/storage-seed/app/public')
        ->toContain('cp -rn');

    // Behavioral: cp -rn fills in missing defaults but never overwrites an
    // existing (operator-uploaded) file of the same name.
    $tmp = sys_get_temp_dir().'/sosvault-seed-'.uniqid();
    mkdir($tmp.'/seed/app/public', 0777, true);
    mkdir($tmp.'/storage/app/public', 0777, true);
    file_put_contents($tmp.'/seed/app/public/logo.png', 'DEFAULT');
    file_put_contents($tmp.'/seed/app/public/doc.png', 'DOC');
    file_put_contents($tmp.'/storage/app/public/logo.png', 'OPERATOR'); // pre-existing

    Process::run(['bash', '-c',
        'cp -rn '.escapeshellarg($tmp.'/seed/app/public').'/. '
        .escapeshellarg($tmp.'/storage/app/public').'/',
    ]);

    expect(file_get_contents($tmp.'/storage/app/public/logo.png'))->toBe('OPERATOR') // preserved
        ->and(file_get_contents($tmp.'/storage/app/public/doc.png'))->toBe('DOC');    // seeded

    exec('rm -rf '.escapeshellarg($tmp));
});

it('ensures a relative public/storage symlink so /storage assets are served', function () {
    $start = file_get_contents(base_path('sysadmin/container_start.sh'));

    // The entrypoint must (re)create public/storage as a RELATIVE link; an
    // absolute target (what `artisan storage:link` writes) breaks across the
    // bind mount and 404s every blog/post image and avatar.
    expect($start)
        ->toContain('ln -sfn ../storage/app/public /var/www/site/public/storage')
        ->toContain('readlink /var/www/site/public/storage');

    // Behavioral: the link text resolves to the public storage dir, relative.
    $tmp = sys_get_temp_dir().'/sosvault-storage-link-'.uniqid();
    mkdir($tmp.'/public', 0777, true);
    mkdir($tmp.'/storage/app/public', 0777, true);
    $cmd = 'cd '.escapeshellarg($tmp).' && ln -sfn ../storage/app/public public/storage'
        .' && readlink public/storage';
    $result = Process::run(['bash', '-c', $cmd]);

    expect(trim($result->output()))->toBe('../storage/app/public');

    exec('rm -rf '.escapeshellarg($tmp));
});

it('passes WWWUSER/WWWGROUP into the app container via compose', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.appliance.yml'));
    $env = $compose['services']['app']['environment'];

    expect($env)
        ->toContain('WWWUSER=${WWWUSER:-1000}')
        ->toContain('WWWGROUP=${WWWGROUP:-1000}');
});
