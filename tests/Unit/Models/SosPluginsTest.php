<?php

use App\Models\SosPlugins;

it('parses sos_plugins.json into rows', function () {
    $rows = SosPlugins::parseJsonFile(base_path('json/sos_plugins.json'));

    expect($rows)->not->toBeEmpty()
        ->and($rows[0])->toHaveKeys([
            'name',
            'short_description',
            'long_description',
            'profiles',
            'options',
            'plugin_default_state',
            'since_version',
        ])
        ->and($rows[0]['profiles'])->toBeString()
        ->and($rows[0]['options'])->toBeString();
});

it('returns an empty array when the JSON file is missing', function () {
    expect(SosPlugins::parseJsonFile('/nonexistent/path/sos_plugins.json'))->toBe([]);
});

it('points sushi cache invalidation at the JSON file', function () {
    $path = (new ReflectionMethod(SosPlugins::class, 'sushiCacheReferencePath'))
        ->invoke(new SosPlugins);

    expect($path)->toBe(base_path('json/sos_plugins.json'))
        ->and(is_file($path))->toBeTrue();
});
