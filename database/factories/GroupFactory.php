<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company()."'s Group",
            'owner_id' => User::factory(),
            'plan_id' => null,
            'vault_id' => null,
            'max_members' => 8,
        ];
    }
}
