<?php

use App\Models\User;
use Database\Seeders\CategoriesTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\SosVaultFeatureDocsSeeder;
use Wave\Category;
use Wave\Post;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    User::factory()->create();
    $this->seed(CategoriesTableSeeder::class);
});

it('runs without throwing when the sos-vault category exists', function () {
    $this->seed(SosVaultFeatureDocsSeeder::class);
})->throwsNoExceptions();

it('leaves the sos-vault category intact', function () {
    $this->seed(SosVaultFeatureDocsSeeder::class);

    expect(Category::where('slug', 'sos-vault')->exists())->toBeTrue();
});

it('seeds the compliance feature doc under the sos-vault category', function () {
    $this->seed(SosVaultFeatureDocsSeeder::class);

    $category = Category::where('slug', 'sos-vault')->firstOrFail();
    $post = Post::where('slug', 'sos-vault-compliance-exposure-assessment')->first();

    expect($post)->not->toBeNull()
        ->and($post->category_id)->toBe($category->id)
        ->and($post->status)->toBe('PUBLISHED')
        ->and($post->title)->toBe('11. Compliance & Exposure Assessment')
        ->and($post->image)->toBe('posts/September2026/compliance-dashboard.png');
});

it('seeds the alerts feature doc under the sos-vault category', function () {
    $this->seed(SosVaultFeatureDocsSeeder::class);

    $category = Category::where('slug', 'sos-vault')->firstOrFail();
    $post = Post::where('slug', 'sos-vault-automated-alerts')->first();

    expect($post)->not->toBeNull()
        ->and($post->category_id)->toBe($category->id)
        ->and($post->status)->toBe('PUBLISHED')
        ->and($post->title)->toBe('12. Automated Alerts')
        ->and($post->image)->toBe('posts/September2026/alerts-dashboard.png');
});

it('is idempotent — re-running does not duplicate posts', function () {
    $this->seed(SosVaultFeatureDocsSeeder::class);
    $this->seed(SosVaultFeatureDocsSeeder::class);

    $category = Category::where('slug', 'sos-vault')->firstOrFail();

    expect(Post::where('category_id', $category->id)->count())->toBe(2);
});
