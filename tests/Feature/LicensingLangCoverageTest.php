<?php

/**
 * lang/{en,es,ja,de}/licensing.php — same shape across all locales, no empty
 * values, non-en differs from en, and the de copy is mirrored into both the
 * module_builder and runtime modules german-support trees.
 *
 * Mirrors PortalLangCoverageTest and ApplianceLangCoverageTest. The
 * flattenKeys / flattenValues helpers there are already defined at top-level
 * scope under if (! function_exists), so we reuse them.
 */
$locales = ['en', 'es', 'ja', 'de'];

if (! function_exists('flattenKeys')) {
    function flattenKeys(array $array, string $prefix = ''): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $compoundKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $keys = array_merge($keys, flattenKeys($value, $compoundKey));
            } else {
                $keys[] = $compoundKey;
            }
        }
        sort($keys);

        return $keys;
    }
}

if (! function_exists('flattenValues')) {
    function flattenValues(array $array, string $prefix = ''): array
    {
        $values = [];
        foreach ($array as $key => $value) {
            $compoundKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $values = array_merge($values, flattenValues($value, $compoundKey));
            } else {
                $values[$compoundKey] = $value;
            }
        }

        return $values;
    }
}

it('publishes a licensing.php in every priority locale', function () use ($locales) {
    foreach ($locales as $locale) {
        $path = lang_path("$locale/licensing.php");
        expect(file_exists($path))->toBeTrue("Missing lang/$locale/licensing.php");
        $contents = require $path;
        expect(is_array($contents))->toBeTrue("lang/$locale/licensing.php must return an array");
    }
});

it('keeps the same key shape across all priority locales', function () use ($locales) {
    $reference = flattenKeys(require lang_path('en/licensing.php'));

    foreach ($locales as $locale) {
        if ($locale === 'en') {
            continue;
        }

        $keys = flattenKeys(require lang_path("$locale/licensing.php"));

        $missingInLocale = array_diff($reference, $keys);
        $extraInLocale = array_diff($keys, $reference);

        expect($missingInLocale)->toBe([], "lang/$locale/licensing.php is missing keys vs en");
        expect($extraInLocale)->toBe([], "lang/$locale/licensing.php has extra keys vs en");
    }
});

it('has no empty translation values', function () use ($locales) {
    foreach ($locales as $locale) {
        $flat = flattenValues(require lang_path("$locale/licensing.php"));
        foreach ($flat as $key => $value) {
            expect($value)->not->toBeEmpty("lang/$locale/licensing.php key [$key] is empty");
        }
    }
});

it('translates key strings away from the en source in non-en locales', function () use ($locales) {
    $en = require lang_path('en/licensing.php');

    $sampledKeys = [
        ['request', 'button_generate'],
        ['user_creating_single_admin'],
        ['disk_manager', 'unlicensed_title'],
    ];

    foreach ($locales as $locale) {
        if ($locale === 'en') {
            continue;
        }

        $entries = require lang_path("$locale/licensing.php");

        foreach ($sampledKeys as $path) {
            $enValue = $en;
            $localeValue = $entries;
            foreach ($path as $segment) {
                $enValue = $enValue[$segment];
                $localeValue = $localeValue[$segment];
            }
            $label = implode('.', $path);
            expect($localeValue)
                ->not->toBe($enValue, "lang/$locale/licensing.php [$label] should differ from en");
        }
    }
});

it('ships the de licensing.php in module_builder and modules german-support copies', function () {
    expect(file_exists(base_path('module_builder/german-support/resources/lang/de/licensing.php')))->toBeTrue();
    expect(file_exists(base_path('modules/german-support/resources/lang/de/licensing.php')))->toBeTrue();

    $appKeys = flattenKeys(require lang_path('de/licensing.php'));
    $moduleBuilderKeys = flattenKeys(require base_path('module_builder/german-support/resources/lang/de/licensing.php'));
    $moduleKeys = flattenKeys(require base_path('modules/german-support/resources/lang/de/licensing.php'));

    expect($moduleBuilderKeys)->toBe($appKeys);
    expect($moduleKeys)->toBe($appKeys);
});

it('reads translated value when the app locale is changed', function () {
    app()->setLocale('es');
    expect(__('licensing.request.button_generate'))->toBe('Generar solicitud de licencia');

    app()->setLocale('de');
    expect(__('licensing.request.button_generate'))->toBe('Lizenzanforderung erzeugen');

    app()->setLocale('ja');
    expect(__('licensing.request.button_generate'))->toBe('ライセンスリクエストを生成');

    app()->setLocale('en');
});
