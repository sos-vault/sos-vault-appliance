<?php

/**
 * PHPUnit bootstrap — runs BEFORE Laravel boots, before any test case is created.
 *
 * Purpose: eliminate every cached file that could redirect tests to the real
 * database or production services (Redis, file sessions, etc.).  Without this
 * step a stale `bootstrap/cache/config.php` silently overrides every `<env>`
 * value in phpunit.xml and may cause tests to hit the live SQLite file.
 */
$cacheDir = __DIR__.'/../bootstrap/cache';

$filesToClear = [
    $cacheDir.'/config.php',
    $cacheDir.'/routes-v7.php',
    $cacheDir.'/routes.php',
    $cacheDir.'/services.php',
    $cacheDir.'/events.php',
];

foreach ($filesToClear as $file) {
    if (file_exists($file)) {
        unlink($file);
    }
}

require __DIR__.'/../vendor/autoload.php';

/*
 * Pull TEST_FIXTURE_PASSPHRASE from the local (gitignored) .env so the parsing
 * tests can decrypt fixtures under test_data/ without the passphrase living in
 * any tracked file. Laravel boots with APP_ENV=testing and loads .env.testing
 * exclusively, so .env values are otherwise invisible during tests.
 */
$envFile = __DIR__.'/../.env';
if (is_file($envFile) && getenv('TEST_FIXTURE_PASSPHRASE') === false) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (! str_starts_with($line, 'TEST_FIXTURE_PASSPHRASE=')) {
            continue;
        }
        $value = trim(substr($line, strlen('TEST_FIXTURE_PASSPHRASE=')));
        if (preg_match('/^"(.*)"$/', $value, $m) || preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }
        putenv("TEST_FIXTURE_PASSPHRASE={$value}");
        $_ENV['TEST_FIXTURE_PASSPHRASE'] = $value;
        $_SERVER['TEST_FIXTURE_PASSPHRASE'] = $value;
        break;
    }
}
