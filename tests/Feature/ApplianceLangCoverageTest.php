<?php

$locales = ['en', 'es', 'ja', 'de'];

it('publishes an appliance.php in every priority locale', function () use ($locales) {
    foreach ($locales as $locale) {
        $path = lang_path("$locale/appliance.php");
        expect(file_exists($path))->toBeTrue("Missing lang/$locale/appliance.php");
        $contents = require $path;
        expect(is_array($contents))->toBeTrue("lang/$locale/appliance.php must return an array");
    }
});

it('keeps the same key shape across all priority locales for appliance.php', function () use ($locales) {
    $reference = flattenKeys(require lang_path('en/appliance.php'));

    foreach ($locales as $locale) {
        if ($locale === 'en') {
            continue;
        }

        $keys = flattenKeys(require lang_path("$locale/appliance.php"));

        $missingInLocale = array_diff($reference, $keys);
        $extraInLocale = array_diff($keys, $reference);

        expect($missingInLocale)->toBe([], "lang/$locale/appliance.php is missing keys vs en");
        expect($extraInLocale)->toBe([], "lang/$locale/appliance.php has extra keys vs en");
    }
});

it('has no empty translation values in appliance.php', function () use ($locales) {
    foreach ($locales as $locale) {
        $flat = flattenValues(require lang_path("$locale/appliance.php"));
        foreach ($flat as $key => $value) {
            expect($value)->not->toBeEmpty("lang/$locale/appliance.php key [$key] is empty");
        }
    }
});

it('translates appliance widget labels away from the en source in non-en locales', function () use ($locales) {
    $en = require lang_path('en/appliance.php');

    foreach ($locales as $locale) {
        if ($locale === 'en') {
            continue;
        }

        $entries = require lang_path("$locale/appliance.php");

        // Spot-check a few visible widget labels that an operator sees on the dashboard
        // and the License page.
        expect($entries['widget_license']['title'])
            ->not->toBe($en['widget_license']['title'], "lang/$locale/appliance.php widget_license.title should differ from en");
        expect($entries['manage_license']['install_button'])
            ->not->toBe($en['manage_license']['install_button'], "lang/$locale/appliance.php manage_license.install_button should differ from en");
    }
});

it('ships the de appliance.php in the module_builder german-support copy', function () {
    expect(file_exists(base_path('module_builder/german-support/resources/lang/de/appliance.php')))->toBeTrue();

    $appKeys = flattenKeys(require lang_path('de/appliance.php'));
    $moduleBuilderKeys = flattenKeys(require base_path('module_builder/german-support/resources/lang/de/appliance.php'));

    expect($moduleBuilderKeys)->toBe($appKeys);
});

it('reads translated appliance nav labels when the app locale is changed', function () {
    app()->setLocale('es');
    expect(__('appliance.nav.license'))->toBe('Licencia');
    expect(__('appliance.nav.disk'))->toBe('Bóveda principal');

    app()->setLocale('de');
    expect(__('appliance.nav.license'))->toBe('Lizenz');

    app()->setLocale('ja');
    expect(__('appliance.nav.license'))->toBe('ライセンス');

    app()->setLocale('en');
});
