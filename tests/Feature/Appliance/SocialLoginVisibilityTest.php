<?php

/**
 * Social login (Google / Facebook / GitHub via Socialite) is SaaS-only — the
 * providers are not configured on the appliance, so the "Continue with …"
 * buttons must not render on the login or register pages there.
 */

use Database\Seeders\RolesTableSeeder;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

it('hides the social login buttons and Sign Up link on the appliance login page', function () {
    config(['product.type' => 'appliance']);

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Continue with Google')
        ->assertDontSee('Continue with Facebook')
        ->assertDontSee('Continue with GitHub')
        ->assertDontSee('auth/google')
        ->assertDontSee('Sign Up here');           // self-service registration is SaaS-only
});

it('shows the social login buttons and Sign Up link on the SaaS login page', function () {
    config(['product.type' => 'saas']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Continue with Google')
        ->assertSee('Continue with GitHub')
        ->assertSee('Sign Up here');
});
