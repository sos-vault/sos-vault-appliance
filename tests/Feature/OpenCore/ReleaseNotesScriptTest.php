<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * scripts/release-notes.php builds the public mirror's GitHub Release notes
 * (publish-opencore.yml) from ChangelogsTableSeeder, on plain PHP.
 */
function releaseNotes(string $args): ProcessResult
{
    return Process::run('php '.escapeshellarg(base_path('scripts/release-notes.php')).' '.$args);
}

function seederEntryFor(string $version): array
{
    $source = file_get_contents(base_path('database/seeders/ChangelogsTableSeeder.php'));
    preg_match_all("/'title' => '([^']*)'/", $source, $titles);

    return array_values(array_filter($titles[1], fn ($t) => str_contains($t, " {$version} ")));
}

it('prints the seeder entry for a version as release notes', function () {
    $result = releaseNotes('2.2.3');

    expect($result->successful())->toBeTrue()
        ->and($result->output())
        ->toContain('Fixes the local AI assistant not starting on new self-hosted installs.')
        ->toContain('<p><strong>Bug fixes</strong></p>')
        ->toContain('CVE-2026-111756')
        ->toContain('Full changelog: https://sos-vault.com/changelog');
});

it('prints the seeder title with --title', function () {
    $result = releaseNotes('2.2.3 --title');

    expect(trim($result->output()))->toBe('sos-vault 2.2.3 Released')
        ->and(seederEntryFor('2.2.3'))->toBe(['sos-vault 2.2.3 Released']);
});

it('keeps a non-standard seeder title such as 2.1.0', function () {
    expect(trim(releaseNotes('2.1.0 --title')->output()))->toBe('sos-vault 2.1.0 — Self-Hosted Edition');
});

it('fails for a version with no seeder entry, and never matches a prefix', function () {
    expect(releaseNotes('9.9.9')->exitCode())->toBe(1)
        ->and(releaseNotes('2.2.30')->exitCode())->toBe(1)
        ->and(releaseNotes('2.2')->exitCode())->toBe(2)
        ->and(releaseNotes('')->exitCode())->toBe(2);
});

it('is wired into the open-core publish workflow', function () {
    $workflow = file_get_contents(base_path('.github/workflows/publish-opencore.yml'));

    expect($workflow)->toContain('php scripts/release-notes.php "$VERSION"')
        ->toContain('--notes-file "$NOTES"')
        ->toContain('discussion_category_name=Announcements');
});

it('has a 2.2.4 entry that warns self-hosted users to upgrade before installing a new license', function () {
    $result = releaseNotes('2.2.4');

    expect($result->exitCode())->toBe(0)
        ->and($result->output())
        ->toContain('upgrade to 2.2.4 before installing a new or renewed license')
        ->toContain('CVE-2026-128083')
        ->toContain('CVE-2026-116689')
        ->toContain('sos-vault-signing-key.asc');

    expect((string) file_get_contents(base_path('public-docs/CHANGELOG.md')))
        ->toContain('## [2.2.4] - 2026-11-14');
});
