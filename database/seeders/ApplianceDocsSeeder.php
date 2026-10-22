<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Wave\Category;
use Wave\Post;
use Wave\User;

/**
 * Product documentation for the appliance.
 *
 * On the appliance these blog posts ARE the product documentation (sos command
 * reference, sos-vault feature docs, and the self-hosted guides), served at
 * /blog/<category>/<slug>. The SaaS build seeds them via DatabaseSeeder, but the
 * appliance boots from a clean DB and only runs ApplianceAdminSeeder, so without
 * this the docs would be missing.
 *
 * Two sources:
 *   - StandaloneDocsSeeder — the hand-maintained 'standalone' self-hosted guides.
 *   - database/seeders/data/appliance-docs.json — the 'sos-command' and
 *     'sos-vault' reference posts (exported from the canonical content; no
 *     per-install or PII data).
 *
 * Idempotent: categories and posts are upserted by slug, so re-running converges.
 * Author is the first user (the admin planted by ApplianceAdminSeeder), so this
 * must run after that user exists.
 */
class ApplianceDocsSeeder extends Seeder
{
    public function run(): void
    {
        // The hand-maintained self-hosted guides (category 'standalone').
        $this->call(StandaloneDocsSeeder::class);

        $authorId = User::query()->orderBy('id')->value('id')
            ?? DB::table('users')->orderBy('id')->value('id')
            ?? 1;

        $data = $this->loadData();

        $categoryIdBySlug = [];
        foreach ($data['categories'] as $category) {
            $categoryIdBySlug[$category['slug']] = Category::updateOrCreate(
                ['slug' => $category['slug']],
                ['name' => $category['name'], 'order' => $category['order'] ?? 1],
            )->id;
        }

        foreach ($data['posts'] as $post) {
            $categorySlug = $post['category_slug'];
            unset($post['category_slug']);

            Post::updateOrCreate(
                ['slug' => $post['slug']],
                array_merge($post, [
                    'author_id' => $authorId,
                    'category_id' => $categoryIdBySlug[$categorySlug] ?? null,
                ]),
            );
        }
    }

    /**
     * @return array{categories: array<int, array<string, mixed>>, posts: array<int, array<string, mixed>>}
     */
    private function loadData(): array
    {
        $path = database_path('seeders/data/appliance-docs.json');
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("ApplianceDocsSeeder: missing data file {$path}");
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
