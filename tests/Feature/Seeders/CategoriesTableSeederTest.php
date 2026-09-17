<?php

use Database\Seeders\CategoriesTableSeeder;
use Wave\Category;

it('creates the sos-command, sos-vault and standalone categories', function () {
    $this->seed(CategoriesTableSeeder::class);

    expect(Category::where('slug', 'sos-command')->exists())->toBeTrue();
    expect(Category::where('slug', 'sos-vault')->exists())->toBeTrue();
    expect(Category::where('slug', 'standalone')->exists())->toBeTrue();

    $sosVault = Category::where('slug', 'sos-vault')->first();
    expect($sosVault->name)->toBe('sos-vault');

    $standalone = Category::where('slug', 'standalone')->first();
    expect($standalone->name)->toBe('Standalone Docs')
        ->and($standalone->order)->toBe(99);
});

it('is idempotent on a second run and preserves existing ids', function () {
    $this->seed(CategoriesTableSeeder::class);

    $before = Category::orderBy('slug')->get(['id', 'slug'])->toArray();

    $this->seed(CategoriesTableSeeder::class);

    $after = Category::orderBy('slug')->get(['id', 'slug'])->toArray();

    expect(Category::count())->toBe(count($before));
    expect($after)->toBe($before);
});
