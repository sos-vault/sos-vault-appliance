<?php

/**
 * Prints the GitHub release notes for one sos-vault version, taken verbatim
 * from its entry in database/seeders/ChangelogsTableSeeder.php — the source of
 * truth for what shipped (see CLAUDE.md "Release changelog"). Used by
 * .github/workflows/publish-opencore.yml when it cuts the public mirror's
 * GitHub Release on a v* tag.
 *
 * Runs on plain PHP with no Composer/Laravel boot: the seeder's only framework
 * touchpoints (the Seeder base class and the DB facade) are stubbed below, and
 * the stubbed insert() just captures the rows.
 *
 * Usage:
 *     php scripts/release-notes.php 2.2.3           # Markdown notes on stdout
 *     php scripts/release-notes.php 2.2.3 --title   # the entry's title only
 *
 * Exits 1 (with a message on stderr) when the seeder has no entry for the
 * version, so the caller can fall back instead of publishing empty notes.
 */

namespace Illuminate\Database {
    class Seeder {}
}

namespace {
    use Database\Seeders\ChangelogsTableSeeder;

    final class DB
    {
        /** @var list<array<string, mixed>> */
        public static array $rows = [];

        public static function table(string $table): object
        {
            return new class
            {
                public function delete(): void {}

                /** @param list<array<string, mixed>> $rows */
                public function insert(array $rows): void
                {
                    DB::$rows = $rows;
                }
            };
        }
    }

    $version = $argv[1] ?? '';
    $titleOnly = in_array('--title', array_slice($argv, 2), true);

    if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        fwrite(STDERR, "usage: php scripts/release-notes.php X.Y.Z [--title]\n");
        exit(2);
    }

    require __DIR__.'/../database/seeders/ChangelogsTableSeeder.php';
    (new ChangelogsTableSeeder)->run();

    // Match the version as a whole token, so 2.2.3 never matches 2.2.30.
    $pattern = '/(?<![\d.])'.preg_quote($version, '/').'(?![\d.])/';
    $entry = null;
    foreach (DB::$rows as $row) {
        if (preg_match($pattern, (string) $row['title'])) {
            $entry = $row;
        }
    }

    if ($entry === null) {
        fwrite(STDERR, "no ChangelogsTableSeeder entry for {$version}\n");
        exit(1);
    }

    if ($titleOnly) {
        echo $entry['title'], "\n";
        exit(0);
    }

    // GitHub renders the seeder's HTML body as-is; the description's lines
    // become their own paragraphs above it.
    $description = implode("\n\n", array_filter(array_map('trim', explode("\n", (string) $entry['description']))));

    echo $description, "\n\n", $entry['body'], "\n\n",
    "Full changelog: https://sos-vault.com/changelog\n";
}
