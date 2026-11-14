<?php

use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Wave\Setting;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::forget('wave_settings');
});

it('renders the self-hosted section on the landing page', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('Run sos-vault on your own infrastructure', false);
    $response->assertSee('Minimum requirements', false);
});

it('hides both download buttons when neither URL is configured', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertDontSee('Download .deb', false);
    $response->assertDontSee('Download .rpm', false);
    $response->assertSee('Packages will be published here once available', false);
});

it('shows the deb download button when the deb URL is set', function () {
    Setting::updateOrCreate(
        ['key' => 'standalone.deb_url'],
        ['display_name' => 'standalone.deb_url', 'value' => 'https://example.com/sos-vault.deb', 'type' => 'text', 'order' => 0],
    );

    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('Download .deb', false);
    $response->assertSee('https://example.com/sos-vault.deb', false);
    $response->assertDontSee('Download .rpm', false);
});

it('shows the rpm download button when the rpm URL is set', function () {
    Setting::updateOrCreate(
        ['key' => 'standalone.rpm_url'],
        ['display_name' => 'standalone.rpm_url', 'value' => 'https://example.com/sos-vault.rpm', 'type' => 'text', 'order' => 0],
    );

    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('Download .rpm', false);
    $response->assertSee('https://example.com/sos-vault.rpm', false);
    $response->assertDontSee('Download .deb', false);
});

it('shows the checksums link only when its URL is set', function () {
    Setting::updateOrCreate(
        ['key' => 'standalone.deb_url'],
        ['display_name' => 'standalone.deb_url', 'value' => 'https://example.com/sos-vault.deb', 'type' => 'text', 'order' => 0],
    );
    Setting::updateOrCreate(
        ['key' => 'standalone.checksums_url'],
        ['display_name' => 'standalone.checksums_url', 'value' => 'https://example.com/SHA256SUMS', 'type' => 'text', 'order' => 0],
    );

    $response = $this->get('/');

    $response->assertSee('Verify with SHA256SUMS', false);
    $response->assertSee('https://example.com/SHA256SUMS', false);
});

it('links to the standalone documentation posts', function () {
    $response = $this->get('/');

    $response->assertSee('/blog/standalone/standalone-installation-guide', false);
    $response->assertSee('/blog/standalone/standalone-minimum-requirements', false);
    $response->assertSee('/blog/standalone/standalone-quick-start', false);
    $response->assertSee('/blog/standalone/standalone-architecture', false);
    $response->assertSee('/blog/standalone/standalone-faq', false);
});
