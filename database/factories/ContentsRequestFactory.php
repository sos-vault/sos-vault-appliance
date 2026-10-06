<?php

namespace Database\Factories;

use App\Models\ContentsRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentsRequest>
 */
class ContentsRequestFactory extends Factory
{
    protected $model = ContentsRequest::class;

    public function definition(): array
    {
        return [
            'vault_id' => fake()->numberBetween(1, 100),
            'dir_id' => fake()->numberBetween(100000, 9999999),
            'file_id' => fake()->numberBetween(1, 99999),
            'status' => 'VALID',
            'comments' => null,
            'url' => null,
            'owner' => 1,
            'group' => 33,
            'perms' => '750',
            'subscription_id' => 0,
            'plan_id' => '0',
            'role_id' => 0,
            'tool_name' => fake()->optional()->word(),
            'case_id' => 0,
        ];
    }
}
