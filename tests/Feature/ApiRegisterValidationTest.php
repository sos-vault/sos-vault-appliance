<?php

use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Config;

/**
 * API registration (A-4) must enforce its validator — previously it built the
 * rules but never checked them, then User::create()'d the raw request (no email
 * format/uniqueness check, no password policy). These assert invalid input is
 * rejected with 422 and never creates a user.
 */
beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Config::set('jwt.secret', str_repeat('a', 32));
    // Isolate validation from the A-1 rate limit so multiple posts don't 429.
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('rejects an empty registration body with 422', function () {
    $this->postJson('/api/register', [])->assertStatus(422);

    expect(User::query()->count())->toBe(0);
});

it('rejects a bad email and a weak password with 422 and creates no user', function () {
    $this->postJson('/api/register', [
        'name' => 'Test User',
        'username' => 'testuser',
        'email' => 'not-an-email',
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ])->assertStatus(422);

    expect(User::where('username', 'testuser')->exists())->toBeFalse()
        ->and(User::where('email', 'not-an-email')->exists())->toBeFalse();
});
