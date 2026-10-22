<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserThread>
 */
class UserThreadFactory extends Factory
{
    protected $model = UserThread::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gid' => 33,
            'thread_id' => null,
            'uploadFiles' => true,
            'wordLimit' => 0,
        ];
    }
}
