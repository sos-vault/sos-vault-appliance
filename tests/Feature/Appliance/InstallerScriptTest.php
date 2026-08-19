<?php

/**
 * Sprint 6 Step D — sysadmin/installer.sh skeleton.
 *
 * The installer is a 15-step bash flow that turns a freshly-imaged host
 * into a working sos-vault appliance. Most steps need real hardware to
 * fully exercise (GPG keyring init, docker compose up, sudo-protected
 * file writes), so these tests cover what's verifiable
 * without root or hardware:
 *   - bash -n syntax check
 *   - --help banner contents
 *   - --dry-run walks every step end-to-end without mutating state
 *   - the script wires Step A's seeder and Step B's sudoers fragments at
 *     the right callsites, and runs no sosreport (licensing uses a key)
 */

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\AssertionFailedError;

function installerPath(): string
{
    return base_path('sysadmin/installer.sh');
}

function runInstaller(string $args = ''): ProcessResult
{
    return Process::env(['SOS_VAULT_DIR' => base_path()])
        ->run('bash '.escapeshellarg(installerPath()).' '.$args);
}

it('ships an executable installer.sh under sysadmin/', function () {
    expect(is_file(installerPath()))->toBeTrue()
        ->and(is_executable(installerPath()))->toBeTrue();
});

it('passes bash -n syntax check', function () {
    $result = Process::run('bash -n '.escapeshellarg(installerPath()));

    expect($result->successful())->toBeTrue($result->errorOutput());
});

it('prints a usage banner with --help', function () {
    $result = Process::run('bash '.escapeshellarg(installerPath()).' --help');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('sos-vault appliance installer')
        ->and($result->output())->toContain('--dry-run');
});

it('rejects unknown arguments', function () {
    $result = Process::run('bash '.escapeshellarg(installerPath()).' --bogus');

    expect($result->successful())->toBeFalse()
        ->and($result->errorOutput())->toContain('unknown argument');
});

it('walks all 15 steps in --dry-run without mutating state', function () {
    $result = runInstaller('--dry-run');

    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

    foreach (range(1, 15) as $n) {
        $marker = sprintf('Step %d/15', $n);
        expect($result->output())->toContain($marker);
    }

    expect($result->output())->toContain('install complete');
});

it('ends with the web interface URL at the resolved host:port', function () {
    $result = runInstaller('--dry-run');

    // The closing banner must give the operator a ready URL to open. In dry-run
    // the host resolves to the <this-host> stub and the port to the 2002 default.
    expect($result->output())
        ->toContain('Installation complete. Access the sos-vault web interface at:')
        ->toContain('https://<this-host>:2002/')
        // ...and a recovery hint (before the URL) for a faulty-cert lockout.
        ->toContain('sysadmin/reset-tls-cert.sh');
});

it('does not invoke any sudo-only command for real in dry-run', function () {
    $result = runInstaller('--dry-run');

    // Anything actually destructive should appear behind the [dry-run] prefix.
    foreach ([
        'docker compose -f',
        'systemctl daemon-reload',
        'ufw allow 80/tcp',
        'install -m 0440',
    ] as $verb) {
        foreach (explode("\n", $result->output()) as $line) {
            if (str_contains($line, $verb)) {
                if (! str_contains($line, '[dry-run]')) {
                    throw new AssertionFailedError(
                        "destructive verb '$verb' was not gated by --dry-run: $line"
                    );
                }
                break;
            }
        }
    }
    expect(true)->toBeTrue();
});

it('drops the sudoers fragments via install -m 0440 owned root:root', function () {
    $result = runInstaller('--dry-run');

    // cert-helper is sudo-free now, so no sos-vault-cert fragment is installed.
    foreach (['sos-vault-machine-token', 'sos-vault-svaultkey'] as $fragment) {
        expect($result->output())->toMatch('#install -m 0440 -o root -g root .*sysadmin/sudoers\.d/'.preg_quote($fragment, '#').' /etc/sudoers\.d/'.preg_quote($fragment, '#').'#');
    }

    // The cert fragment must NOT be installed anymore.
    expect($result->output())->not->toContain('sysadmin/sudoers.d/sos-vault-cert /etc/sudoers.d/sos-vault-cert');
});

it('runs ApplianceAdminSeeder with creds passed via -e env vars', function () {
    $result = runInstaller('--dry-run');

    // The compose exec line should appear once and carry the seeder
    // class plus -e INSTALLER_ADMIN_{NAME,EMAIL,PASSWORD} flags.
    $seedLine = null;
    foreach (explode("\n", $result->output()) as $line) {
        if (str_contains($line, 'php artisan db:seed') && str_contains($line, 'ApplianceAdminSeeder')) {
            $seedLine = $line;
            break;
        }
    }

    expect($seedLine)->not->toBeNull('expected a db:seed line invoking ApplianceAdminSeeder');
    expect($seedLine)->toContain('-e INSTALLER_ADMIN_NAME=');
    expect($seedLine)->toContain('-e INSTALLER_ADMIN_EMAIL=');
    expect($seedLine)->toContain('-e INSTALLER_ADMIN_PASSWORD=');
    expect($seedLine)->toContain('sudo -u www-data');
    expect($seedLine)->toContain('php artisan db:seed');
});

it('preserves the INSTALLER_ADMIN_* env across the sudo drop to www-data', function () {
    $body = file_get_contents(installerPath());

    // The exec runs as root then `sudo -u www-data`, but the container sudoers
    // sets `Defaults env_reset`, which strips the -e vars before php runs. The
    // seeder reads them with getenv(), so sudo must --preserve-env them.
    expect($body)
        ->toContain('--preserve-env=INSTALLER_ADMIN_NAME,INSTALLER_ADMIN_EMAIL,INSTALLER_ADMIN_PASSWORD');
});

it('passes the seeder FQCN with single backslashes, not bash-doubled ones', function () {
    $body = file_get_contents(installerPath());

    // A bash single-quoted '\\' is TWO literal backslashes (single quotes do
    // not process escapes), so artisan received "Database\\Seeders\\Appliance…"
    // and threw "Target class […] does not exist". The arg must use single
    // backslashes so Laravel resolves Database\Seeders\ApplianceAdminSeeder.
    expect($body)
        ->toContain('--class=\'Database\Seeders\ApplianceAdminSeeder\'')
        ->not->toContain('Database\\\\Seeders');
});

it('does not run any sosreport during install (licensing uses a pasted key)', function () {
    $body = file_get_contents(installerPath());

    // The installer no longer captures an sosreport — the licensing flow
    // generates a copy/paste machine key from the admin UI instead, keeping
    // install fast. The capture-server-report command stays available for
    // manual support use, just not wired into the installer.
    expect($body)
        ->not->toContain('capture-server-report')
        ->not->toContain('sosreport');
});

it('writes a production .env with a short session lifetime', function () {
    $body = file_get_contents(installerPath());

    // The self-hosted appliance runs in production, not local, and uses a short
    // (10 minute) session lifetime so an unattended admin session expires quickly.
    expect($body)
        ->toContain('APP_ENV=production')
        ->toContain('SESSION_LIFETIME=10')
        ->not->toContain('APP_ENV=local')
        ->not->toContain('SESSION_LIFETIME=20');
});

it('captures the host hardware fingerprint into encrypted settings after seeding', function () {
    $body = file_get_contents(installerPath());

    // Step 13b gathers identifiers on the host (root + dmidecode) and hands
    // them to the artisan store command in the container, preserving the env
    // across the sudo drop (same pattern as the admin-seeder creds).
    expect($body)
        ->toContain('step_13b_capture_fingerprint')
        ->toContain('php artisan sos-vault:store-machine-fingerprint')
        ->toContain('dmidecode -s system-uuid')
        ->toContain('--preserve-env=INSTALLER_FP_MACHINE_ID,INSTALLER_FP_DMI_UUID,INSTALLER_FP_BOARD_SERIAL,INSTALLER_FP_SYSTEM_SERIAL');
});

it('creates a fresh empty sqlite DB before migrating (deb ships none)', function () {
    $body = file_get_contents(installerPath());

    // The live DB lives under storage/app/db (bind-mounted, persists across
    // image pulls); the baked database/ dir is NOT on the host. Step 13 must
    // create an empty file there before `migrate --force` runs, owned by the app
    // uid, and only when missing (resume-safe).
    expect($body)
        ->toContain('storage/app/db/database.sqlite')
        ->not->toContain('${SOS_VAULT_DIR}/database/database.sqlite')
        ->toContain('chown "${APP_UID}:${APP_GID}" "$sqlite_db"');

    $createDb = strpos($body, ': > "$sqlite_db"');
    $migrate = strpos($body, 'php artisan migrate --force');
    expect($createDb)->toBeInt()
        ->and($migrate)->toBeInt()
        ->and($createDb)->toBeLessThan($migrate);
});

it('runs migrate --force inside the app container before seeding', function () {
    $result = runInstaller('--dry-run');

    $migratePos = strpos($result->output(), 'php artisan migrate --force');
    $seedPos = strpos($result->output(), 'php artisan db:seed');

    expect($migratePos)->toBeInt()
        ->and($seedPos)->toBeInt()
        ->and($migratePos)->toBeLessThan($seedPos);
});

it('pulls docker images from the registry instead of loading bundled tarballs', function () {
    $body = file_get_contents(installerPath());

    // #1: images come from GHCR/docker.io at install — no bundled-tarball load.
    // ("docker load" may still appear in a comment as the air-gap fallback;
    // assert the actual load INVOCATION is gone, not the word.)
    expect($body)
        ->toContain('docker compose -f "${SOS_VAULT_DIR}/docker-compose.yml" pull')
        ->not->toContain('docker load -i');
});

it('brings the stack up without building on the host', function () {
    $body = file_get_contents(installerPath());

    expect($body)->toMatch('/up -d\s*\\\\\s*\n\s*--no-build --pull never --remove-orphans/');
});

it('mints a per-install self-signed cert before compose up (nginx needs it)', function () {
    $body = file_get_contents(installerPath());

    // The deb ships no cert; nginx crash-loops without one, so step 8 must
    // generate the per-install self-signed pair BEFORE `docker compose up`.
    expect($body)
        ->toContain('ensure_self_signed_cert()')
        ->toContain('CN=sos-vault.local');

    $ensureInStep8 = strpos($body, 'self-signed pair FIRST');
    $composeUp = strpos($body, 'up -d \\');
    expect($ensureInStep8)->toBeInt()
        ->and($composeUp)->toBeInt()
        ->and($ensureInStep8)->toBeLessThan($composeUp);
});

it('does not download the AI model during install (deferred to admin UI)', function () {
    $result = runInstaller('--dry-run');

    // Step 10 only prepares the bind-mount dir; the ~1.1 GB model is fetched
    // later from the admin "Software Updates" page, never by the installer.
    expect($result->output())
        ->toContain('Step 10/15 — preparing AI model directory')
        ->not->toContain('sos-vault:download-model');
});

it('points the operator to the admin Software Updates page for the AI model', function () {
    $result = runInstaller('--dry-run');

    expect($result->output())
        ->toContain('Software Updates')
        ->toContain('bot LLM model is NOT installed yet');
});

it('declares one bash function per step', function () {
    $body = file_get_contents(installerPath());

    foreach (range(1, 15) as $n) {
        $fn = sprintf('step_%02d_', $n);
        expect($body)->toContain($fn);
    }

    // The sudoers helper is its own function.
    expect($body)->toContain('install_sudoers_fragments');
});

it('step 6 skips destructive key-device re-init when the device + escrow already exist', function () {
    $body = file_get_contents(installerPath());

    // init.sh dd-zeroes + luksFormats a FRESH device with new random svault
    // keys, so re-running it after a partial install would discard the keys
    // existing vaults were sealed with. Step 6 must guard on the device +
    // escrowed policy already being present and skip re-init then.
    expect($body)
        ->toContain('-f "${SVAULT_KEYDIR}/svault.key" && -f "${SVAULT_KEYDIR}/policy"')
        ->toContain('skipping re-init (idempotent)');
});

it('still walks init + escrow in dry-run so the guard is install-only', function () {
    $result = runInstaller('--dry-run');

    // The idempotency guard is gated on DRY_RUN -ne 1, so a dry-run always
    // exercises the init.sh + escrow path (existing coverage depends on it).
    expect($result->output())
        ->toContain('Step 6/15 — initializing GPG keyring')
        ->toContain('escrowing passphrase');
});

it('execs migrate/seed against the "app" compose service, not the container_name', function () {
    $body = file_get_contents(installerPath());

    // docker compose exec resolves SERVICE names. The compose service is `app`
    // (its container_name is "sos-vault"); passing the container_name makes
    // compose report "service sos-vault is not running" even when it is up.
    expect($body)
        ->toContain('SOS_VAULT_APP_SERVICE="${SOS_VAULT_APP_SERVICE:-app}"')
        ->not->toContain('SOS_VAULT_APP_CONTAINER');
});

it('waits for the app container to accept an exec before migrating', function () {
    $body = file_get_contents(installerPath());

    // Step 11 fires `systemctl enable --now`, which returns before the stack is
    // ready; step 13 must block on the container instead of racing it.
    expect($body)
        ->toContain('wait_for_app()')
        ->toContain('wait_for_app "${compose[@]}"');
});

it('caches prompted answers and wipes them only on full success', function () {
    $body = file_get_contents(installerPath());

    // A resumed install must not re-ask for the admin creds / passphrase: the
    // answers are cached after step 5b and cleared at step 16.
    expect($body)
        ->toContain('load_answer_cache')
        ->toContain('save_answer_cache')
        ->toContain('clear_answer_cache')
        // The cache lives outside SVAULT_KEYDIR (chowned to the app uid) so the
        // app user can never read the admin password.
        ->toContain('/var/lib/sos-vault/.install-answers');

    // load runs before the prompts; clear runs after the success banner.
    $loadPos = strpos($body, 'load_answer_cache');
    $savePos = strpos($body, 'save_answer_cache');
    $clearPos = strpos($body, 'clear_answer_cache');
    expect($loadPos)->toBeLessThan($savePos)
        ->and($savePos)->toBeLessThan($clearPos);
});

it('skips the prompts when answers were restored from the cache', function () {
    $body = file_get_contents(installerPath());

    // Each prompt step returns early when its value is already populated.
    expect($body)
        ->toContain('admin credentials reused from a previous run')
        ->toContain('passphrase reused from a previous run')
        ->toContain('storage policy reused from a previous run');
});

it('passes shellcheck if available', function () {
    foreach (['/usr/bin/shellcheck', '/usr/local/bin/shellcheck'] as $bin) {
        if (is_executable($bin)) {
            $result = Process::run([$bin, '--severity=error', installerPath()]);
            expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

            return;
        }
    }
    $this->markTestSkipped('shellcheck not available on this host');
});
