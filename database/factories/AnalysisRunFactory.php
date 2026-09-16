<?php

namespace Database\Factories;

use App\Models\AnalysisRun;
use App\Models\SupportCase;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisRun>
 */
class AnalysisRunFactory extends Factory
{
    protected $model = AnalysisRun::class;

    public function definition(): array
    {
        return [
            'case_id' => SupportCase::factory(),
            'vault_id' => Vault::factory(),
            'dir_id' => fake()->numberBetween(100000, 9999999),
            'status' => 'queued',
            'triggered_by' => 'unpack',
            'started_at' => null,
            'completed_at' => null,
            'error_message' => null,
            'summary' => null,
        ];
    }

    public function running(): static
    {
        return $this->state([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'summary' => ['total' => 0, 'failed' => 0],
        ]);
    }
}
