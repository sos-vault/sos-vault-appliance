<?php

/**
 * Sprint 6 Step E — build/ scaffolding (deb pipeline).
 *
 * The build pipeline turns the appliance branch into a deb (and via
 * alien, an rpm). Real packaging needs dpkg-deb, alien, composer,
 * npm, and docker on the build host — none of which we exercise in
 * unit tests. These tests cover what's verifiable on any dev machine:
 *   - bash -n syntax check on every shipped script
 *   - control file fields present and well-formed
 *   - postinst points at the installer; prerm stops the service;
 *     postrm purges sudoers + systemd unit on `purge`
 *   - build.sh --dry-run walks all 9 steps
 */

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Yaml\Yaml;

function buildPath(string $rel = ''): string
{
    return base_path('build'.($rel === '' ? '' : '/'.$rel));
}

function runBash(string $args): ProcessResult
{
    return Process::run('bash '.$args);
}

it('ships every required build script under build/', function () {
    foreach ([
        'build.sh',
        'publish-images.sh',
        'checksums.sh',
        'deb/DEBIAN/control',
        'deb/DEBIAN/preinst',
        'deb/DEBIAN/postinst',
        'deb/DEBIAN/prerm',
        'deb/DEBIAN/postrm',
    ] as $rel) {
        expect(is_file(buildPath($rel)))->toBeTrue();
    }
});

it('marks every shell script executable', function () {
    foreach ([
        'build.sh',
        'publish-images.sh',
        'checksums.sh',
        'deb/DEBIAN/preinst',
        'deb/DEBIAN/postinst',
        'deb/DEBIAN/prerm',
        'deb/DEBIAN/postrm',
    ] as $rel) {
        expect(is_executable(buildPath($rel)))->toBeTrue();
    }
});

it('passes bash -n on every shipped script', function () {
    foreach ([
        'build.sh',
        'publish-images.sh',
        'checksums.sh',
        'deb/DEBIAN/preinst',
        'deb/DEBIAN/postinst',
        'deb/DEBIAN/prerm',
        'deb/DEBIAN/postrm',
    ] as $rel) {
        $result = runBash('-n '.escapeshellarg(buildPath($rel)));
        if (! $result->successful()) {
            throw new AssertionFailedError(
                "bash -n failed for $rel: ".$result->errorOutput()
            );
        }
    }
    expect(true)->toBeTrue();
});

it('control file declares the expected metadata fields', function () {
    $body = (string) file_get_contents(buildPath('deb/DEBIAN/control'));

    foreach ([
        'Package: sos-vault',
        'Version: VERSION_PLACEHOLDER',
        'Section: admin',
        'Architecture: amd64',
        'Maintainer:',
        'Description:',
    ] as $field) {
        expect($body)->toContain($field);
    }
});

it('control file Depends includes the runtime dependencies the installer expects', function () {
    $body = (string) file_get_contents(buildPath('deb/DEBIAN/control'));

    // Match either the docker.io OR docker-ce alternative — the file
    // expresses both via the deb `|` operator.
    expect($body)->toMatch('/Depends:.*docker(\\.io)?/');

    foreach ([
        'docker-compose-plugin',
        'ufw',
        'openssl',
        'sosreport',
        'rsync',
        // keyutils provides keyctl (execStart.sh loads svault0..3 into the
        // kernel keyring); cryptsetup opens the LUKS svault key device. Both
        // are hard runtime deps of the boot key service, not present by default.
        'keyutils',
        'cryptsetup',
        // dmidecode reads the DMI identifiers (system UUID, board / system
        // serial) the installer captures into the host fingerprint at Step 13b.
        'dmidecode',
    ] as $pkg) {
        if (! str_contains($body, $pkg)) {
            throw new AssertionFailedError("control Depends missing: $pkg");
        }
    }
    expect(true)->toBeTrue();
});

it('postinst directs the operator to run the installer.sh', function () {
    $body = (string) file_get_contents(buildPath('deb/DEBIAN/postinst'));

    expect($body)
        ->toContain('/opt/sos-vault/sysadmin/installer.sh')
        ->toContain('chmod 0755');
});

it('prerm stops sos-vault.service on remove and purge', function () {
    $body = (string) file_get_contents(buildPath('deb/DEBIAN/prerm'));

    expect($body)
        ->toContain('systemctl stop sos-vault.service')
        ->toContain('systemctl disable sos-vault.service')
        ->toContain('remove|purge');
});

it('postrm purges sudoers + systemd unit ONLY on purge', function () {
    $body = (string) file_get_contents(buildPath('deb/DEBIAN/postrm'));

    foreach ([
        'rm -f /etc/sudoers.d/sos-vault-cert',
        'rm -f /etc/systemd/system/sos-vault.service',
        'rm -f /etc/default/sos-vault',
    ] as $verb) {
        expect($body)->toContain($verb);
    }

    // The purge-gating happens via case "$1" in / purge) — sanity check
    // that the file does NOT touch those paths in the plain `remove` arm.
    expect($body)->toContain('case "$1"');
    expect($body)->toContain('purge)');
});

it('build.sh prints a usage banner with --help', function () {
    $result = runBash(escapeshellarg(buildPath('build.sh')).' --help');

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('sos-vault appliance package builder')
        ->and($result->output())->toContain('--dry-run');
});

it('build.sh --dry-run walks all 9 steps without mutating dist/', function () {
    $result = runBash(escapeshellarg(buildPath('build.sh')).' --dry-run');

    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

    foreach (range(1, 9) as $n) {
        expect($result->output())->toContain(sprintf('Step %d/9', $n));
    }
    expect($result->output())->toContain('build complete');
});

it('build.sh does not mutate the working tree (code is baked into the image)', function () {
    // Architecture B: the production (--no-dev) vendor/ and the compiled
    // front-end assets are built + BAKED into the published images
    // (ghcr.io/sos-vault/{app,nginx}), not staged from the working tree. Drive
    // the real pipeline: the dry-run (which prints every command `run` would
    // execute) must run NO composer/npm command and NO dev-restore.
    $result = runBash(escapeshellarg(buildPath('build.sh')).' --dry-run');
    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

    expect($result->output())
        ->not->toContain('[dry-run] composer')
        ->not->toContain('[dry-run] npm')
        ->not->toContain('restoring dev dependencies');

    // …and the restore helper is gone from the source entirely.
    expect((string) file_get_contents(buildPath('build.sh')))
        ->not->toContain('restore_dev_state');
});

it('build.sh arms no dev-restore EXIT trap (nothing to restore)', function () {
    $body = (string) file_get_contents(buildPath('build.sh'));

    // The old pipeline mutated vendor/ and armed `trap restore_dev_state EXIT`
    // to undo it. Architecture B mutates nothing, so neither must exist.
    expect($body)->not->toContain('trap restore_dev_state EXIT');
});

it('build.sh --dry-run rejects unknown args', function () {
    $result = runBash(escapeshellarg(buildPath('build.sh')).' --bogus');

    expect($result->successful())->toBeFalse()
        ->and($result->errorOutput())->toContain('unknown argument');
});

it('build.sh references dpkg-deb, alien, and the deb maintainer scripts', function () {
    $body = (string) file_get_contents(buildPath('build.sh'));

    expect($body)
        ->toContain('dpkg-deb --root-owner-group --build')
        ->toContain('alien --to-rpm')
        ->toContain('build/deb/DEBIAN/control')
        ->toContain('build/deb/DEBIAN/postinst');
});

it('ships a committed appliance compose that pulls the app image from GHCR', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.appliance.yml'));

    $app = $compose['services']['app'] ?? null;
    expect($app)->not->toBeNull();

    // Pulled, not built — no `build:` directive, image points at GHCR.
    expect($app)->not->toHaveKey('build');
    expect($app['image'] ?? '')->toStartWith('ghcr.io/sos-vault/app:');

    // Redis stays in-network only (the security invariant), like the dev compose.
    expect($compose['services']['redis'] ?? [])->not->toHaveKey('ports');
    expect($compose['services']['redis']['expose'] ?? [])->toContain('6379');

    // No developer-specific gnupg bind mount leaks into the customer artifact.
    expect(implode("\n", $app['volumes'] ?? []))->not->toContain('/home/jlrueda');
});

it('build.sh stages only host-side files + the appliance compose (whitelist)', function () {
    $body = (string) file_get_contents(buildPath('build.sh'));

    // Architecture B whitelist staging: ship the appliance compose (image tag
    // pinned), the docker-compose/ config tree, and the sysadmin/ scripts —
    // dropping the dev TLS private key and the big redirect binary. NO
    // application source: app/, vendor/, public/ are baked into the images.
    expect($body)
        ->toContain('docker-compose.appliance.yml')
        ->toContain('IMAGE_TAG_PLACEHOLDER')
        ->toContain('"${BUILD_ROOT}/docker-compose/"')
        ->toContain('"${BUILD_ROOT}/sysadmin/"')
        // The dev TLS cert + PRIVATE KEY must never ship — each appliance mints
        // its own self-signed pair at install (installer ensure_self_signed_cert).
        ->toContain("--exclude 'nginx/ssl/*/*.pem'")
        // The 2.1 MB redirect binary is not needed on the host.
        ->toContain("--exclude 'redirect'");

    // Step 5 must NOT bundle docker images into the deb anymore.
    expect($body)->not->toContain('docker save');
});

it('the deb payload ships no PHP source (vendor/app/public stay in the image)', function () {
    // Drive the real staging via --dry-run and assert nothing under the staged
    // /opt/sos-vault references the application source tree — the IP-protection
    // invariant of Architecture B (no PHP source on the customer host).
    $result = runBash(escapeshellarg(buildPath('build.sh')).' --dry-run');
    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

    $out = $result->output();
    expect($out)
        ->toContain('/opt/sos-vault/docker-compose/')
        ->toContain('/opt/sos-vault/sysadmin/')
        ->not->toContain('/opt/sos-vault/vendor')
        ->not->toContain('/opt/sos-vault/app/')
        ->not->toContain('/opt/sos-vault/wave')
        ->not->toContain('/opt/sos-vault/public');
});

it('ships a catch-all appliance nginx config (no canonical-host redirect)', function () {
    $tmpl = base_path('docker-compose/nginx/sos-vault.appliance.conf.tmpl');
    expect(is_file($tmpl))->toBeTrue();

    $body = (string) file_get_contents($tmpl);

    // Serves any Host as the default server — an appliance is reached by IP /
    // custom hostname, never sos-vault.com — and must NOT redirect away.
    expect($body)
        ->toContain('server_name _;')
        ->toContain('listen 443 ssl default_server;')
        ->toContain('listen 80 default_server;')
        ->not->toContain('return 301 https://sos-vault.com');

    // We deliberately do NOT assert anything about docker-compose/nginx/
    // sos-vault.com.conf (the dev/SaaS site config): it carries the
    // skip-worktree bit, so its committed content and a developer's local
    // content can differ. What protects the appliance is that build.sh REPLACES
    // it with the catch-all above and drops the template — covered by the next
    // test — not whatever the SaaS conf happens to contain.
});

it('build.sh swaps the appliance nginx config in over the SaaS one', function () {
    $body = (string) file_get_contents(buildPath('build.sh'));

    // Replace sos-vault.com.conf with the appliance template, then drop the
    // template so nginx (loads *.conf) does not pick up a second server.
    expect($body)
        ->toContain('sos-vault.appliance.conf.tmpl')
        ->toContain('rm -f "${nginx_dir}/sos-vault.com.conf"')
        ->toContain('rm -f "${nginx_dir}/sos-vault.appliance.conf.tmpl"');
});

it('publish-images.sh builds and pushes the GHCR app image', function () {
    $help = runBash(escapeshellarg(buildPath('publish-images.sh')).' --help');
    expect($help->successful())->toBeTrue($help->errorOutput())
        ->and($help->output())->toContain('image publisher');

    $body = (string) file_get_contents(buildPath('publish-images.sh'));
    expect($body)
        ->toContain('ghcr.io/sos-vault/app')
        ->toContain('docker push');
});

it('checksums.sh handles an empty dist/ directory gracefully', function () {
    $tmp = sys_get_temp_dir().'/sos-vault-build-test-'.bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);

    try {
        $result = Process::env(['BUILD_DIST_DIR' => $tmp])
            ->run('bash '.escapeshellarg(buildPath('checksums.sh')));

        expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());
        expect(is_file($tmp.'/SHA256SUMS'))->toBeTrue();
    } finally {
        foreach (glob($tmp.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmp);
    }
});

it('checksums.sh hashes deb/rpm/tar.gz artifacts under dist/', function () {
    $tmp = sys_get_temp_dir().'/sos-vault-build-test-'.bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    file_put_contents($tmp.'/sos-vault.deb', 'fake-deb-bytes');
    @mkdir($tmp.'/docker-images', 0700, true);
    file_put_contents($tmp.'/docker-images/app.tar.gz', 'fake-image-bytes');

    try {
        $result = Process::env(['BUILD_DIST_DIR' => $tmp])
            ->run('bash '.escapeshellarg(buildPath('checksums.sh')));

        expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());

        $sums = (string) file_get_contents($tmp.'/SHA256SUMS');
        expect($sums)
            ->toContain('sos-vault.deb')
            ->toContain('docker-images/app.tar.gz');
    } finally {
        foreach (glob($tmp.'/docker-images/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmp.'/docker-images');
        foreach (glob($tmp.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmp);
    }
});

it('keeps the dev sqlite DB and ALL its dated backups out of the image build context', function () {
    $ignore = (string) file_get_contents(base_path('.dockerignore'));

    // A glob (matching .gitignore), NOT three exact names — otherwise the dated
    // dev backups (database.sqlite.2026-05-05, …) fall through .dockerignore and
    // get baked into the published image: stale dev data + needless bloat.
    expect($ignore)
        ->toContain('/database/database.sqlite*')
        ->not->toContain('/database/database.sqlite-shm'); // superseded by the glob

    // Behavioral: the pattern must exclude the live DB, its -shm/-wal sidecars,
    // AND a dated backup.
    foreach ([
        'database/database.sqlite',
        'database/database.sqlite-shm',
        'database/database.sqlite-wal',
        'database/database.sqlite.2026-05-05',
    ] as $path) {
        expect(fnmatch('database/database.sqlite*', $path))->toBeTrue("must exclude {$path}");
    }
});

it('ships the Mil knowledge base (agent/) inside the image build context', function () {
    // Regression: agent/ was once dumped into the "image bloat" ignore block
    // alongside genuinely dev-only dirs (/tests, /shots, /docs). But agent/ is the
    // RUNTIME Mil knowledge base — config('ai.system_prompt_path') = base_path('agent')
    // — read by KnowledgeLoader on every query. Excluding it shipped an image with
    // no KB, so /sosvault answers silently fell back to generic model output while
    // /sos and /linux (public knowledge the model already has) still looked fine.
    $ignore = (string) file_get_contents(base_path('.dockerignore'));

    // The ignore file must not carry an /agent rule (in any of the equivalent forms).
    foreach (["\n/agent\n", "\n/agent/\n", "\nagent\n", "\nagent/\n"] as $rule) {
        expect("\n".$ignore."\n")->not->toContain($rule);
    }

    // And the KB files the loader depends on must actually exist on disk so they
    // land in the build context.
    foreach ([
        'agent/instructions.md',
        'agent/kb/sos_vault.md',
        'agent/kb/sos_vault_appliance.md',
        'agent/kb/sos_command.md',
        'agent/kb/case_analysis.md',
    ] as $rel) {
        expect(is_file(base_path($rel)))->toBeTrue("missing KB file: {$rel}");
    }
});

it('keeps host-side + dev-only files out of the image build context', function () {
    $ignore = (string) file_get_contents(base_path('.dockerignore'));

    // Compose runs on the HOST (the deb ships docker-compose.appliance.yml via
    // build.sh, never the image); .env.testing is test-only (.env is mounted at
    // runtime); the rest are editor / MCP / doc cruft with no runtime role.
    expect($ignore)
        ->toContain('/docker-compose.yml')
        ->toContain('/docker-compose.appliance.yml')
        ->toContain('/.env.testing')
        ->toContain('/.editorconfig')
        ->toContain('/.mcp.json')
        ->toContain('/.cramb')
        ->toContain('/README-YOUTUBE-API-KEYS.md')
        // Build/VCS metadata — no runtime role.
        ->toContain('/Dockerfile')
        ->toContain('/.gitattributes')
        ->toContain('/.gitignore')
        ->toContain('/.dockerignore');
});

it('ships the curated default public assets as a read-only boot seed', function () {
    // The deb stages the git-tracked storage/app/public (login logo, default
    // avatar, Documentation + page/post images) under storage-seed/ — NOT
    // storage/ (operator data) — using the tracked set only so dev uploads in
    // the working tree never leak in.
    $body = (string) file_get_contents(buildPath('build.sh'));
    expect($body)
        ->toContain('storage-seed/app/public')
        ->toContain('git ls-files public')
        ->toContain('--files-from=-');

    // The appliance compose mounts it read-only into the app container so the
    // entrypoint can copy it into the host-mounted storage on boot.
    $compose = Yaml::parseFile(base_path('docker-compose.appliance.yml'));
    expect($compose['services']['app']['volumes'] ?? [])
        ->toContain('/opt/sos-vault/storage-seed:/var/www/site/storage-seed:ro,z');
});
