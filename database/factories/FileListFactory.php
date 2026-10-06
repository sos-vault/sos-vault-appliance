<?php

namespace Database\Factories;

use App\Models\FileList;
use App\Models\SupportCase;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileList>
 */
class FileListFactory extends Factory
{
    protected $model = FileList::class;

    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'user_id' => 1,
            'case_id' => SupportCase::factory(),
            'vault_id' => Vault::factory(),
            'dir_id' => fake()->numberBetween(100000, 9999999),
            'name' => $name,
            'title' => $name,
            'status' => 'available',
            'icon' => 'phosphor-files-duotone',
            'enabled' => true,
        ];
    }
}
