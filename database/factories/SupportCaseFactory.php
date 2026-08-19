<?php

namespace Database\Factories;

use App\Models\SupportCase;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportCase>
 */
class SupportCaseFactory extends Factory
{
    protected $model = SupportCase::class;

    public function definition(): array
    {
        $sosid = fake()->bothify('????????');
        $host = fake()->domainWord();
        $date = fake()->date('Y-m-d');

        return [
            'secured' => false,
            'gpg' => false,
            'tar' => false,
            'obfuscated' => false,
            'sosreport' => 'sosreport',
            'label' => fake()->word(),
            'host' => $host,
            'case' => 'CASE-'.fake()->numerify('####'),
            'date' => $date,
            'sosid' => $sosid,
            'compression' => 'xz',
            'customer' => fake()->optional()->company(),
            'version' => null,
            'serial' => 0,
            'file_id' => 0,
            'fstatus' => 'AVAILABLE',
            'vault_id' => Vault::factory(),
            'owner' => 1,
            'group' => 33,
            'perms' => '750',
            'subscription_id' => 0,
            'plan_id' => '0',
            'role_id' => 0,
            'status' => 'OPEN',
        ];
    }

    public function closed(): static
    {
        return $this->state(['status' => 'CLOSED', 'fstatus' => 'AVAILABLE']);
    }

    public function blocked(): static
    {
        return $this->state(['status' => 'BLOCKED']);
    }
}
