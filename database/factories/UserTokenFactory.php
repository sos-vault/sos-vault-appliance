<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserToken>
 */
class UserTokenFactory extends Factory
{
    protected $model = UserToken::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'input_tokens_used' => 0,
            'output_tokens_used' => 0,
            'total_tokens_used' => 0,
            'queries_made' => 0,
            'reports_made' => 0,
            'input_tokens_available' => 10000,
            'output_tokens_available' => 10000,
            'total_tokens_available' => 20000,
            'number_of_sessions' => 0,
        ];
    }
}
