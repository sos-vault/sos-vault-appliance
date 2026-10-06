<?php

use App\Models\User;
use Database\Seeders\RolesTableSeeder;

use function Pest\Laravel\get;

it('responds with 200 for all public routes', function (string $route) {
    $response = get($route);
    $response->assertStatus(200);
})->with('routes');

test('responds with 200 for all auth routes', function (string $url) {
    $this->seed(RolesTableSeeder::class);
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get($url);
    $response->assertStatus(200);
})->with('authroutes');
