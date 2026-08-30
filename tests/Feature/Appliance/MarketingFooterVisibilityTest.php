<?php

/**
 * The marketing footer (Features, Pricing, Status, Terms & Conditions, social
 * links, etc.) is SaaS-only. The appliance build has no public marketing
 * surface, so the footer partial must render to nothing there.
 */

use Database\Seeders\RolesTableSeeder;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

it('hides the marketing footer on the appliance', function () {
    config(['product.type' => 'appliance']);

    $html = view('theme::partials.footer')->render();

    expect(trim($html))->toBe('')
        ->and($html)->not->toContain('<footer')
        ->and($html)->not->toContain('fa-facebook');     // social links gone
});

it('renders the marketing footer on SaaS', function () {
    config(['product.type' => 'saas']);

    $html = view('theme::partials.footer')->render();

    expect($html)->toContain('<footer')
        ->and($html)->toContain('fa-facebook');          // social links present
});
