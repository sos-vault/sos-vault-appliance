<?php

namespace Database\Factories;

use App\Models\Module;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_type' => 'module',
            'module_id' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'version' => $this->faker->semver(),
            'description' => $this->faker->sentence(),
            'author' => $this->faker->name(),
            'provider' => null,
            'tool_name' => null,
            'tool_slug' => null,
            'tool_icon' => null,
            'is_enabled' => true,
            'installed_at' => now(),
        ];
    }

    public function patch(): static
    {
        return $this->state(['package_type' => 'patch', 'is_enabled' => true]);
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }

    public function withTool(): static
    {
        return $this->state([
            'tool_name' => $this->faker->words(2, true),
            'tool_slug' => $this->faker->slug(1),
            'tool_icon' => 'phosphor-star-duotone',
        ]);
    }
}
