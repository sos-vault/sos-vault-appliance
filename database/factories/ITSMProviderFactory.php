<?php

namespace Database\Factories;

use App\Models\ITSMProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ITSMProvider>
 */
class ITSMProviderFactory extends Factory
{
    protected $model = ITSMProvider::class;

    public function definition(): array
    {
        return [
            'vid' => 1,
            'uid' => 1,
            'gid' => 33,
            'provider' => fake()->unique()->randomElement(['jira', 'servicenow', 'zendesk']),
            'url' => fake()->url(),
            'tenant' => fake()->optional()->domainWord(),
            'client_id' => null,
            'client_secret' => null,
            'user' => fake()->optional()->userName(),
            'password' => null,
            'api_key' => null,
            'api_token' => null,
            'customer_field' => null,
        ];
    }
}
