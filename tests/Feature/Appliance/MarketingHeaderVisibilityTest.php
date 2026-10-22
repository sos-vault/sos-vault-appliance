<?php

/**
 * The marketing top-bar nav (Solution, Pricing, Learn) and the guest
 * auth buttons (Login + Sign Up) are SaaS-only. The appliance build has no
 * public marketing surface — guests are redirected straight to the login page —
 * so the header keeps only the logo (and the dashboard link for authenticated
 * users).
 */

use Database\Seeders\RolesTableSeeder;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

it('hides the marketing nav and guest auth buttons on the appliance', function () {
    config(['product.type' => 'appliance']);

    $html = view('theme::components.marketing.elements.header')->render();

    expect($html)
        ->not->toContain('/login')            // Login gone — guests go straight to login anyway
        ->not->toContain('/register')         // Sign Up gone
        ->not->toContain(route('pricing'))    // Pricing menu gone
        ->not->toContain(route('blog'));      // Learn menu gone
});

it('renders the marketing nav and Sign Up button on SaaS', function () {
    config(['product.type' => 'saas']);

    $html = view('theme::components.marketing.elements.header')->render();

    expect($html)
        ->toContain('/login')
        ->toContain('/register')              // Sign Up present
        ->toContain(route('pricing'))         // Pricing menu present
        ->toContain(route('blog'));           // Learn menu present
});
