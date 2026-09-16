<?php

/**
 * The appliance build has no public marketing landing page. The root path `/`
 * is overridden (routes/web.php) so that the Folio "home" marketing page is
 * never reachable: guests land on the login screen, authenticated users on
 * their role-based dashboard. This branch boots with config('product.type') =
 * 'appliance' (config/product.php), so the override route is registered.
 */

use App\Models\User;
use Database\Seeders\RolesTableSeeder;

beforeEach(function () {
    // The `/` override is registered at boot from config/product.php
    // ('appliance' on this branch); align runtime config so the rest of the
    // request behaves as an appliance too (Pest.php globally forces 'saas').
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);
});

it('redirects a guest from the root path to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('redirects the Folio marketing aliases to the login page for guests', function () {
    // /index and /home both resolve to the same index.blade.php marketing page
    // via Folio — they must redirect to login on an appliance too.
    $this->get('/index')->assertRedirect(route('login'));
    $this->get('/home')->assertRedirect(route('login'));
});

it('redirects an authenticated user from the root path to their dashboard', function () {
    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->get('/')
        ->assertRedirect(route('auth.home'));
});

it('does not render the marketing landing page at the root path', function () {
    // The Folio marketing home (named 'home') must not serve `/` — the override
    // wins, so the response is a redirect, never a 200 HTML landing page.
    $this->get('/')->assertStatus(302);
});
