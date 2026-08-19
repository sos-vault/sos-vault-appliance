<?php

namespace Database\Factories;

use App\Models\Sysevent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sysevent>
 */
class SyseventFactory extends Factory
{
    protected $model = Sysevent::class;

    public function definition(): array
    {
        return [
            'vault_id' => fake()->numberBetween(1, 100),
            'dir_id' => 0,
            'case_id' => 0,
            'status' => 'SUCCESS',
            'type' => fake()->randomElement(['upload', 'open', 'close', 'extract', 'delete']),
            'class' => 'NORMAL',
            'payload' => null,
            'owner' => 1,
            'group' => 33,
            'ip' => fake()->optional()->ipv4(),
        ];
    }

    public function failed(): static
    {
        return $this->state(['status' => 'FAILED', 'class' => 'ERROR']);
    }
}
