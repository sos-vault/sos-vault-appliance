<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\SupportCase;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bookmark>
 */
class BookmarkFactory extends Factory
{
    protected $model = Bookmark::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'case_id' => SupportCase::factory(),
            'vault_id' => Vault::factory(),
            'dir_id' => fake()->numberBetween(100000, 9999999),
            'filelist_id' => null,
            'name' => fake()->word(),
            'fullpath' => 'proc/cpuinfo',
            'filetype' => 'file',
            'icon' => 'phosphor-file-duotone',
            'enabled' => true,
        ];
    }
}
