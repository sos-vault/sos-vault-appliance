<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\SupportCase;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'case_id' => SupportCase::factory(),
            'vault_id' => Vault::factory(),
            'dir_id' => fake()->numberBetween(100000, 9999999),
            'name' => fake()->slug(3),
            'title' => fake()->sentence(4),
            'excerpt' => fake()->optional()->sentence(),
            'document' => null,
            'description' => fake()->optional()->paragraph(),
            'type' => 'ai',
            'status' => 'published',
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }
}
