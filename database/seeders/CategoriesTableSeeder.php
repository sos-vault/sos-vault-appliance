<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Wave\Category;

class CategoriesTableSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->categories() as $category) {
            Category::updateOrCreate(
                ['slug' => $category['slug']],
                $category,
            );
        }
    }

    private function categories(): array
    {
        return [
            [
                'name' => 'Marketing',
                'slug' => 'marketing',
                'parent_id' => null,
                'order' => 1,
            ],
            [
                'name' => 'Tutorials',
                'slug' => 'tutorials',
                'parent_id' => null,
                'order' => 1,
            ],
            [
                'name' => 'sos command',
                'slug' => 'sos-command',
                'parent_id' => null,
                'order' => 1,
            ],
            [
                'name' => 'sos-vault',
                'slug' => 'sos-vault',
                'parent_id' => null,
                'order' => 1,
            ],
            [
                'name' => 'Standalone Docs',
                'slug' => 'standalone',
                'parent_id' => null,
                'order' => 99,
            ],
        ];
    }
}
