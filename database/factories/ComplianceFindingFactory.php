<?php

namespace Database\Factories;

use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComplianceFinding>
 */
class ComplianceFindingFactory extends Factory
{
    protected $model = ComplianceFinding::class;

    public function definition(): array
    {
        $run = AnalysisRun::factory();

        return [
            'analysis_run_id' => $run,
            'case_id' => fake()->numberBetween(1, 9999),
            'vault_id' => fake()->numberBetween(1, 9999),
            'ruleset' => fake()->randomElement(ComplianceFinding::RULESETS),
            'rule_id' => fake()->bothify('RULE-####'),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'severity' => fake()->randomElement(ComplianceFinding::SEVERITIES),
            'status' => fake()->randomElement(ComplianceFinding::STATUSES),
            'category' => fake()->optional()->word(),
            'matched_path' => fake()->optional()->filePath(),
            'evidence' => null,
            'remediation' => fake()->optional()->sentence(),
            'reference_url' => null,
        ];
    }

    public function failing(): static
    {
        return $this->state(['status' => 'fail']);
    }
}
