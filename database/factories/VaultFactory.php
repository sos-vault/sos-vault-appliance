<?php

namespace Database\Factories;

use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vault>
 */
class VaultFactory extends Factory
{
    protected $model = Vault::class;

    public function definition(): array
    {
        $hash = md5(fake()->unique()->userName());

        return [
            'user_vault' => $hash,
            'device' => "/vault/.{$hash}.img",
            'header_file' => "/vault/.headers/{$hash}.header",
            'key' => Str::random(64),
            'status' => 'CLOSED',
            'owner' => fake()->unique()->numberBetween(100, 9999),
            'group' => 33,
            'perms' => '700',
            'shared_status' => 'PRIVATE',
            'description' => fake()->optional()->sentence(),
            'subscription_id' => 0,
            'plan_id' => '',
            'role_id' => 1,
            'current_size' => 0,
            'plan_size' => 500,
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => 'OPEN']);
    }

    public function forUser(int $userId): static
    {
        return $this->state(['owner' => $userId]);
    }
}
