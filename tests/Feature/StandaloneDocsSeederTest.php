<?php

use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\StandaloneDocsSeeder;
use Wave\Category;
use Wave\Post;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    User::factory()->create();
});

$expectedSlugs = [
    'standalone-minimum-requirements',
    'standalone-installation-guide',
    'standalone-administration-guide',
    'standalone-user-guide',
    'standalone-quick-start',
    'standalone-faq',
    'standalone-troubleshooting',
    'standalone-architecture',
    'standalone-open-core',
];

it('creates the standalone category', function () {
    $this->seed(StandaloneDocsSeeder::class);

    $cat = Category::where('slug', 'standalone')->first();

    expect($cat)->not->toBeNull();
    expect($cat->name)->toBe('Standalone Docs');
});

it('creates all nine docs posts under the standalone category', function () use ($expectedSlugs) {
    $this->seed(StandaloneDocsSeeder::class);

    $category = Category::where('slug', 'standalone')->firstOrFail();
    $posts = Post::where('category_id', $category->id)->get();

    expect($posts)->toHaveCount(9);
    expect($posts->pluck('slug')->sort()->values()->all())
        ->toBe(collect($expectedSlugs)->sort()->values()->all());
});

it('publishes posts with non-empty bodies', function () {
    $this->seed(StandaloneDocsSeeder::class);

    $posts = Post::whereHas('category', fn ($q) => $q->where('slug', 'standalone'))->get();

    $posts->each(function (Post $post) {
        expect($post->status)->toBe('PUBLISHED');
        expect(strlen($post->body))->toBeGreaterThan(200);
        expect($post->title)->not->toBeEmpty();
    });
});

it('is idempotent on second run', function () {
    $this->seed(StandaloneDocsSeeder::class);
    $this->seed(StandaloneDocsSeeder::class);

    expect(Category::where('slug', 'standalone')->count())->toBe(1);
    expect(Post::whereHas('category', fn ($q) => $q->where('slug', 'standalone'))->count())->toBe(9);
});

it('shows standalone posts in the public blog index query', function () {
    $this->seed(StandaloneDocsSeeder::class);

    // Mirror the query used in resources/themes/anchor/pages/blog/index.blade.php.
    $publicPosts = Post::orderBy('created_at', 'ASC')->get();

    expect($publicPosts->pluck('slug'))->toContain('standalone-installation-guide');
});

it('shows the standalone category in the public nav query', function () {
    $this->seed(StandaloneDocsSeeder::class);

    $navCategories = Category::all();

    expect($navCategories->pluck('slug'))->toContain('standalone');
});
