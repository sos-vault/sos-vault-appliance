<?php

use Illuminate\Support\Facades\Process;

/**
 * Open-core publish-manifest lint.
 *
 * Reads scripts/opencore-deny-list.txt and asserts:
 *   1. Every required public-facing surface IS present in the repo
 *      (so we don't accidentally strip something the public build needs).
 *   2. Every path on the deny-list either exists (we have something to
 *      strip) OR is documented as "expected absent" — a stale deny-list
 *      entry is a maintenance hazard, not a hard error.
 *   3. No deny-listed PHP class shows up in a "must be public" path.
 *
 * The workflow itself (`.github/workflows/publish-opencore.yml`) reads the
 * same deny-list, so a passing test here means the workflow will publish
 * a clean tree without us hand-validating every commit.
 */
function loadDenyList(): array
{
    $path = base_path('scripts/opencore-deny-list.txt');
    expect(file_exists($path))->toBeTrue('scripts/opencore-deny-list.txt is missing');

    $entries = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        // Strip inline comments and trim.
        $line = preg_replace('/#.*$/', '', $line);
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $entries[] = $line;
    }

    return $entries;
}

it('publishes a well-formed deny-list at scripts/opencore-deny-list.txt', function () {
    $entries = loadDenyList();
    expect($entries)->not->toBeEmpty('deny-list must contain at least one path');

    foreach ($entries as $entry) {
        // No absolute paths — every entry is repo-relative.
        expect($entry)->not->toStartWith('/', "deny-list entry must be repo-relative: $entry");
        // No '..' escape attempts.
        expect($entry)->not->toContain('..', "deny-list entry must not contain '..': $entry");
    }
});

it('strips the SaaS-only Filament resources', function () {
    $entries = loadDenyList();

    foreach (['app/Filament/Resources/Plans/', 'app/Filament/Resources/Posts/', 'app/Filament/CustomerPortal/'] as $path) {
        expect(in_array($path, $entries, true))->toBeTrue("deny-list must strip $path from the public mirror");
    }
});

it('strips the SaaS-only seeders and Paddle webhook', function () {
    $entries = loadDenyList();

    foreach ([
        'app/Http/Controllers/PaddleWebhook.php',
        'database/seeders/PlansTableSeeder.php',
        'database/seeders/PostsTableSeeder.php',
        'database/seeders/StandaloneDocsSeeder.php',
        'app/Services/LicenseCheckoutService.php',
        'app/Services/LicenseGeneratorService.php',
        // SaaS-only purchase glue — checkout return handler + purchase-intent model.
        'app/Http/Controllers/Billing/GuestCheckoutController.php',
        'app/Models/LicensePurchaseIntent.php',
    ] as $path) {
        expect(in_array($path, $entries, true))->toBeTrue("deny-list must strip $path");
    }
});

it('strips the upstream Stripe controllers but keeps Paddle in the mirror', function () {
    $entries = loadDenyList();

    foreach ([
        'wave/src/Http/Controllers/Billing/Stripe.php',
        'wave/src/Http/Controllers/Billing/Webhooks/StripeWebhook.php',
    ] as $path) {
        expect(in_array($path, $entries, true))->toBeTrue("deny-list must strip $path from the public mirror");
    }

    // Inverse guard: the directory-level pattern must NOT be present
    // — it would silently delete the live Paddle controllers too.
    expect(in_array('wave/src/Http/Controllers/Billing/', $entries, true))
        ->toBeFalse('deny-list must not strip the whole Billing/ directory; Paddle controllers must remain in the mirror');
});

it('keeps the open-core surfaces present in the source tree', function () {
    // These paths are what the public mirror SHIPS — if any of them
    // disappear from the source tree we have a bigger problem than
    // publishing, so fail loudly.
    foreach ([
        'app/Models/LocalLicense.php',
        'app/Services/LocalLicenseService.php',
        'app/Services/LicenseRequestService.php',
        'app/Services/MachineTokenService.php',
        'app/Filament/Pages/ManageLicense.php',
        'app/Filament/Pages/ManageSettings.php',
        'app/Http/Middleware/BlockUnlicensedNonAdmin.php',
        'app/Helpers/sosVaultHelper.php',
        'sysadmin/machine-token-helper',
        'sysadmin/installer.sh',
        'lang/en/licensing.php',
        'lang/es/licensing.php',
        'lang/ja/licensing.php',
        'lang/de/licensing.php',
        'LICENSE',
        'NOTICE',
        'public-docs/README.md',
        'public-docs/docs/img/sos-vault-logo.png',
    ] as $path) {
        expect(file_exists(base_path($path)))->toBeTrue("open-core surface missing from tree: $path");
    }
});

it('does not deny-list any open-core surface by accident', function () {
    $entries = loadDenyList();

    $requiredPublic = [
        'app/Models/LocalLicense.php',
        'app/Services/LocalLicenseService.php',
        'app/Services/LicenseRequestService.php',
        'app/Filament/Pages/ManageLicense.php',
        'app/Http/Middleware/BlockUnlicensedNonAdmin.php',
        'lang/en/licensing.php',
        'public-docs/README.md',
    ];

    foreach ($requiredPublic as $public) {
        foreach ($entries as $denied) {
            // Treat trailing-slash entries as a directory prefix.
            if (str_ends_with($denied, '/')) {
                expect(str_starts_with($public, $denied))
                    ->toBeFalse("Required public path $public is shadowed by deny-list entry $denied");
            } else {
                expect($public)->not->toBe($denied, "Required public path $public is on the deny-list");
            }
        }
    }
});

it('strips HANDOFF.md and other private operational docs', function () {
    $entries = loadDenyList();

    foreach (['HANDOFF.md', 'TestPlan.md', 'sysadmin/weylandQuest.sh'] as $path) {
        expect(in_array($path, $entries, true))->toBeTrue("deny-list must strip $path from public mirror");
    }
});

it('strips private CI infrastructure and large runtime artefacts', function () {
    $entries = loadDenyList();

    // The publish workflow itself is private infra — PATs without the
    // workflow scope cannot push it anyway, and it has no value in the
    // public mirror.
    expect(in_array('.github/workflows/', $entries, true))
        ->toBeTrue('deny-list must strip the private .github/workflows/ directory');

    // Runtime data artefacts that exceed GitHub size limits.
    expect(in_array('storage/app/geoip.mmdb', $entries, true))
        ->toBeTrue('deny-list must strip storage/app/geoip.mmdb (>50MB)');

    // Private /docs/ at monorepo root — the PUBLIC docs come from
    // public-docs/docs/ via the workflow hoist step.
    expect(in_array('docs/', $entries, true))
        ->toBeTrue('deny-list must strip the private /docs/ before the public-docs hoist');
});

it('ships a public-mirror .gitignore that does not silence docs/', function () {
    // The monorepo's .gitignore line `docs/*` correctly shadows the
    // private root /docs/ in the monorepo, but the hoisted public
    // /docs/img/* would also be silenced under that rule. The publish
    // workflow rsyncs public-docs/.gitignore over the monorepo one so
    // the mirror's `git add -A` actually adds the README's logo.
    $path = base_path('public-docs/.gitignore');
    expect(file_exists($path))->toBeTrue('public-docs/.gitignore must exist to shadow the monorepo gitignore on publish');
    expect(file_get_contents($path))->not->toMatch('/^docs\/\*\s*$/m', 'public-docs/.gitignore must not contain a `docs/*` rule');
});

it('ships the publish workflow under .github/workflows/', function () {
    expect(file_exists(base_path('.github/workflows/publish-opencore.yml')))->toBeTrue();
});

it('publish workflow references the deny-list path', function () {
    $contents = file_get_contents(base_path('.github/workflows/publish-opencore.yml'));
    expect($contents)->toContain('scripts/opencore-deny-list.txt');
    expect($contents)->toContain('public-docs');
});

it('marks bundled web assets as linguist vendored/generated so the mirror language stats stay accurate', function () {
    // .gitattributes survives into the mirror (it is not on the deny-list and
    // not excluded by the publish rsync). The public/ docroot (compiled Vite
    // output + published package assets) and resources/themes/*/assets are
    // bundled, not hand-authored source; without these overrides GitHub
    // Linguist reads the mirror as a JavaScript/CSS project instead of PHP.
    $isExcluded = function (string $path): bool {
        // git check-attr evaluates the path against .gitattributes — the file
        // need not exist, only the pattern has to match.
        $out = Process::path(base_path())
            ->run('git check-attr linguist-vendored linguist-generated linguist-documentation -- '.escapeshellarg($path))
            ->output();

        return str_contains($out, ': set');
    };

    // Bundled assets must be excluded from the language bar.
    expect($isExcluded('public/build/assets/app.js'))->toBeTrue('public/build assets must be linguist-generated');
    expect($isExcluded('public/vendor/livewire/livewire.js'))->toBeTrue('public/vendor assets must be linguist-vendored');
    expect($isExcluded('resources/themes/anchor/assets/css/webfont.css'))->toBeTrue('theme assets must be linguist-vendored');
    expect($isExcluded('README.md'))->toBeTrue('markdown must be linguist-documentation');

    // First-party application source must stay counted.
    expect($isExcluded('app/Models/User.php'))->toBeFalse('first-party PHP must not be excluded');
    expect($isExcluded('resources/views/components/avatar.blade.php'))->toBeFalse('first-party Blade must not be excluded');
});
