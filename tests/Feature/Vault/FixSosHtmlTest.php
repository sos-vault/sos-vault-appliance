<?php

use App\Events\FixSosHtmlRequested;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Config;

/*
 * fixSosHtml() rewrites the sos_reports/sos.html index so its on-disk relative
 * links ("../<report-relative-path>") become working File-Viewer URLs, and marks
 * the file so the viewer renders it as HTML instead of escaped text. New reports
 * are fixed at unpack time (summaryData); older ones by the queued FixSosHtml
 * listener when the case is opened. These tests use the no-LUKS temp-dir harness
 * (a real VaultTools over real files, vaults "disabled").
 */

function fixSosHtmlEnv(): array
{
    $root = sys_get_temp_dir().'/sos-vault-fix-sos-html-'.bin2hex(random_bytes(4));
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

// Lay down a minimal but realistic extracted sosreport, generate the file tree
// caches, and return the handles a test needs. $sosHtmlBody lets a test pad the
// index past the 1 MB chunk threshold.
function fixSosMakeReport(array $env, string $sosHtmlBody = ''): array
{
    $mount = $env['mount'];
    $dirName = 'sosreport-host0-PROD-2026-01-01-fixsos';
    $src = "{$mount}/{$dirName}";

    mkdir("{$src}/sos_reports", 0o755, true);
    mkdir("{$src}/etc", 0o755, true);

    file_put_contents("{$src}/etc/chrony.conf", "server pool.ntp.org iburst\n");
    // Colon-delimited so DataTools::sosVersion() (run in the ctor) parses it.
    file_put_contents("{$src}/version.txt", "sosreport: 4.7.2\n");
    file_put_contents("{$src}/foo.html", "<html><body>not the index</body></html>\n");

    $html = "<!DOCTYPE html><html><head><title>sos</title></head><body>\n"
        ."<a href=\"../etc/chrony.conf\">/etc/chrony.conf</a>\n"
        ."<a href=\"../version.txt\">version</a>\n"
        ."<a href=\"../etc/missing\">missing</a>\n"
        ."<a href=\"#anchor\">jump</a>\n"
        .$sosHtmlBody
        ."</body></html>\n";
    file_put_contents("{$src}/sos_reports/sos.html", $html);

    $vtools = new VaultTools($env['user'], $env['vault']->id);
    // Root cache (maxdepth 1) so getDirById($did) resolves the report dir, then
    // the report's own recursive tree.
    $vtools->updateContents();
    $vtools->getContents($src);

    return [
        'vtools' => $vtools,
        'src' => $src,
        'did' => fileinode($src),
        'sosHtml' => "{$src}/sos_reports/sos.html",
        'chronyFid' => fileinode("{$src}/etc/chrony.conf"),
        'versionFid' => fileinode("{$src}/version.txt"),
        'fooFid' => fileinode("{$src}/foo.html"),
    ];
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    $root = Config::get('filesystems.disks.vault.root');
    if ($root && str_starts_with($root, sys_get_temp_dir().'/sos-vault-fix-sos-html-')) {
        exec('/bin/rm -rf '.escapeshellarg($root).' 2>/dev/null');
    }
});

it('rewrites ../ links to File-Viewer URLs and injects the fixed marker', function () {
    $env = fixSosHtmlEnv();
    $r = fixSosMakeReport($env);
    $cid = 4242;

    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);
    expect($dtools->fixSosHtml($cid))->toBeTrue();

    $out = file_get_contents($r['sosHtml']);

    // Marker present. It must NOT lead the file (a leading HTML comment would
    // make file(1) misclassify the document and break viewer delivery).
    expect($out)->toContain(DataTools::SOS_HTML_FIXED_MARKER);
    expect(str_starts_with($out, '<!DOCTYPE html>'))->toBeTrue();

    // Real files → working viewer links (new tab), keyed by their inode (fid).
    expect($out)->toContain("href=\"/filebrowser/{$cid}/{$r['chronyFid']}\" target=\"_blank\"");
    expect($out)->toContain("href=\"/filebrowser/{$cid}/{$r['versionFid']}\" target=\"_blank\"");

    // A listed-but-absent file and the in-page anchor are left untouched.
    expect($out)->toContain('href="../etc/missing"');
    expect($out)->toContain('href="#anchor"');
    // The original ../ file links are gone.
    expect($out)->not->toContain('href="../etc/chrony.conf"');
});

it('is idempotent — a second run is a no-op and leaves the file byte-identical', function () {
    $env = fixSosHtmlEnv();
    $r = fixSosMakeReport($env);

    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);
    expect($dtools->fixSosHtml(4242))->toBeTrue();
    $first = file_get_contents($r['sosHtml']);

    // Fresh DataTools (as the background listener would build) — still a no-op.
    $dtools2 = new DataTools($r['vtools'], $env['vault']->id, $r['did']);
    expect($dtools2->fixSosHtml(4242))->toBeTrue();
    $second = file_get_contents($r['sosHtml']);

    expect($second)->toBe($first);
    expect(substr_count($second, DataTools::SOS_HTML_FIXED_MARKER))->toBe(1);
});

it('resolves the case id from the SupportCase when none is passed', function () {
    $env = fixSosHtmlEnv();
    $r = fixSosMakeReport($env);

    $case = SupportCase::create([
        'case' => 'PROD-001',
        'path' => basename($r['src']),
        'owner' => $env['user']->id,
        'group' => $env['user']->id,
        'perms' => 0o700,
        'file_id' => $r['did'],
        'vault_id' => $env['vault']->id,
    ]);

    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);
    expect($dtools->fixSosHtml())->toBeTrue();

    expect(file_get_contents($r['sosHtml']))
        ->toContain("href=\"/filebrowser/{$case->id}/{$r['chronyFid']}\" target=\"_blank\"");
});

it('serves the fixed sos.html whole and un-escaped even past the chunk threshold', function () {
    $env = fixSosHtmlEnv();
    // Pad past tooBig (1 MB) so, without the special case, it would be chunked.
    $r = fixSosMakeReport($env, str_repeat("<p>filler line</p>\n", 70000));
    $cid = 4242;

    $sosFid = fileinode($r['sosHtml']);  // tree key (captured before the atomic rewrite)

    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);
    expect($dtools->fixSosHtml($cid))->toBeTrue();
    expect(filesize($r['sosHtml']))->toBeGreaterThan(1048576);

    $served = $r['vtools']->getFileContentsById($env['vault']->id, $r['did'], $sosFid, 0, $cid);

    expect($served->isSosHtml)->toBeTrue();
    expect($served->chunked)->toBeFalse();
    // Raw HTML, not htmlspecialchars-escaped.
    expect($served->contents)->toContain('<a href="/filebrowser/');
    expect($served->contents)->not->toContain('&lt;a href=');
});

it('leaves every other .html file as escaped text (only sos.html renders as HTML)', function () {
    $env = fixSosHtmlEnv();
    $r = fixSosMakeReport($env);
    $cid = 4242;

    $dtools = new DataTools($r['vtools'], $env['vault']->id, $r['did']);
    $dtools->fixSosHtml($cid);

    $served = $r['vtools']->getFileContentsById($env['vault']->id, $r['did'], $r['fooFid'], 0, $cid);

    expect($served->isSosHtml)->toBeFalse();
});

it('the queued FixSosHtml listener fixes an older report when its case is opened', function () {
    $env = fixSosHtmlEnv();
    $r = fixSosMakeReport($env);

    $case = SupportCase::create([
        'case' => 'PROD-001',
        'path' => basename($r['src']),
        'owner' => $env['user']->id,
        'group' => $env['user']->id,
        'perms' => 0o700,
        'file_id' => $r['did'],
        'vault_id' => $env['vault']->id,
    ]);

    // Not yet fixed.
    expect(file_get_contents($r['sosHtml']))->not->toContain(DataTools::SOS_HTML_FIXED_MARKER);

    // queue.default is 'sync' in tests, so this runs the listener inline.
    event(new FixSosHtmlRequested($env['user']->id, $env['vault']->id, $r['did'], $case->id));

    $out = file_get_contents($r['sosHtml']);
    expect($out)->toContain(DataTools::SOS_HTML_FIXED_MARKER);
    expect($out)->toContain("href=\"/filebrowser/{$case->id}/{$r['chronyFid']}\" target=\"_blank\"");
});
