<?php

/**
 * On the appliance the blog posts ARE the product documentation. The clean
 * install DB seeds them via ApplianceDocsSeeder (called from ApplianceAdminSeeder):
 * the hand-maintained 'standalone' self-hosted guides plus the 'sos-command' and
 * 'sos-vault' reference posts exported to database/seeders/data/appliance-docs.json.
 */

use App\Models\User;
use Database\Seeders\ApplianceDocsSeeder;
use Database\Seeders\RolesTableSeeder;
use Wave\Category;
use Wave\Post;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    User::factory()->create(); // author for the docs (mirrors first-boot admin)
});

it('seeds the documentation categories and their posts', function () {
    (new ApplianceDocsSeeder)->run();

    foreach (['sos-command', 'sos-vault', 'standalone'] as $slug) {
        expect(Category::where('slug', $slug)->exists())->toBeTrue();
    }

    // 18 sos-command + 10 sos-vault (JSON) + 9 standalone (StandaloneDocsSeeder).
    expect(Post::count())->toBeGreaterThanOrEqual(37);

    $socId = Category::where('slug', 'sos-command')->value('id');
    expect(Post::where('category_id', $socId)->count())->toBe(18);
});

it('attributes the posts to the first (admin) user', function () {
    $authorId = User::query()->orderBy('id')->value('id');

    (new ApplianceDocsSeeder)->run();

    expect(Post::query()->pluck('author_id')->unique()->all())->toBe([$authorId]);
});

it('is idempotent — re-running does not duplicate posts', function () {
    (new ApplianceDocsSeeder)->run();
    $count = Post::count();

    (new ApplianceDocsSeeder)->run();

    expect(Post::count())->toBe($count);
});
