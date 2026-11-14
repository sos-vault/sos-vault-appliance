<?php

use Illuminate\Support\Facades\Process;

/**
 * The repo's .gnupg/ is the appliance's PUBLIC verification keyring. GnuPG
 * keeps secret keys under private-keys-v1.d/, so that directory (and the
 * local tofu.db) must be gitignored and must never hold tracked files —
 * in the private repo and in the public mirror's .gitignore alike.
 */
it('gitignores secret-key material inside the repo keyring', function (string $path) {
    $result = Process::path(base_path())->run(['git', 'check-ignore', '-q', '--no-index', $path]);

    expect($result->exitCode())->toBe(0, "{$path} is not gitignored");
})->with([
    '.gnupg/private-keys-v1.d/ABCDEF0123456789ABCDEF0123456789ABCDEF01.key',
    '.gnupg/tofu.db',
]);

it('tracks no files under .gnupg/private-keys-v1.d', function () {
    $tracked = Process::path(base_path())->run(['git', 'ls-files', '.gnupg/private-keys-v1.d']);

    expect(trim($tracked->output()))->toBe('');
});

it('ignores secret-key material in the public mirror .gitignore too', function () {
    $mirrorIgnore = (string) file_get_contents(base_path('public-docs/.gitignore'));

    expect($mirrorIgnore)->toContain('.gnupg/private-keys-v1.d/')
        ->toContain('.gnupg/tofu.db');
});
