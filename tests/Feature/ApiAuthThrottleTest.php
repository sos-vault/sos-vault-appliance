<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

/**
 * The API auth endpoints must be tightly rate-limited (A-1) — the generic api
 * limiter (60/min) is far too permissive for credential brute-force / stuffing /
 * automated signup.
 */
beforeEach(function () {
    // A signing secret so JWTAuth::attempt() reaches a clean 401 (bad creds).
    Config::set('jwt.secret', str_repeat('a', 32));
});

it('throttles POST /api/login at 5 requests/min (functional)', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertStatus(401);
    }

    $this->postJson('/api/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
        ->assertStatus(429);
});

it('applies the intended tight throttle to every API auth route', function () {
    $expected = [
        'api/login' => 'throttle:5,1',
        'api/register' => 'throttle:3,1',
        'api/refresh' => 'throttle:10,1',
        'api/token' => 'throttle:10,1',
        'api/upload' => 'throttle:30,1',
    ];

    foreach ($expected as $uri => $throttle) {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods(), true));

        expect($route)->not->toBeNull("route {$uri} should exist")
            ->and($route->gatherMiddleware())->toContain($throttle);
    }
});
