<?php

namespace Database\Factories;

use App\Models\Annotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Annotation>
 */
class AnnotationFactory extends Factory
{
    protected $model = Annotation::class;

    public function definition(): array
    {
        return [
            'vault_id' => fake()->numberBetween(1, 100),
            'dir_id' => fake()->numberBetween(100000, 9999999),
            'file_id' => fake()->numberBetween(1, 99999),
            'title' => fake()->sentence(4),
            'status' => 'PRIVATE',
            'locked' => false,
            'acetate' => null,
            'owner' => 1,
            'group' => 33,
            'perms' => '750',
            'subscription_id' => 0,
            'plan_id' => '0',
            'role_id' => 0,
        ];
    }

    public function public(): static
    {
        return $this->state(['status' => 'PUBLIC']);
    }
}
