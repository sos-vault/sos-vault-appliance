<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Laravel\Folio\Folio;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the application and immediately force the testing database
     * settings before RefreshDatabase (or any trait) runs migrations.
     *
     * `defineEnvironment()` does NOT exist in Laravel's test infrastructure —
     * the correct hook is to override `createApplication()` and set config
     * on the returned app instance before returning it.
     */
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // Register a `booting` callback that fires AFTER config is loaded but
        // BEFORE any service provider's boot() method runs.  This is the only
        // reliable hook that overrides cached config values before providers
        // (e.g. PluginServiceProvider) try to use production services such as Redis.
        $app->booting(static function ($app): void {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', ':memory:');
            $app['config']->set('cache.default', 'array');
            $app['config']->set('session.driver', 'array');
            $app['config']->set('queue.default', 'sync');
            $app['config']->set('app.vaultsDisabled', 'TRUE');
        });

        $app->make(Kernel::class)->bootstrap();

        // Second safety layer: verify the override took effect after bootstrap.
        $resolved = $app['config']->get('database.connections.sqlite.database');
        if ($resolved !== ':memory:') {
            throw new RuntimeException(
                "Test safety: database path is '{$resolved}' instead of ':memory:'. ".
                'Tests must never run against a real database file.'
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // ThemesServiceProvider and WaveServiceProvider register theme assets
        // at boot time by querying the DB for the active theme. In the
        // in-memory test DB the themes table is empty at boot, so nothing
        // gets registered. We replicate those registrations here so
        // HTTP-level tests resolve routes and views correctly.
        $themeFolder = resource_path('themes/anchor');
        $themePages = $themeFolder.'/pages';
        $themeComponents = $themeFolder.'/components';

        // Folio page routes
        if (! collect(Folio::mountPaths())->contains(fn ($mp) => str_contains($mp->path, 'themes/anchor/pages'))) {
            Folio::path($themePages);
        }

        // Blade anonymous components
        Blade::anonymousComponentPath($themeComponents.'/elements');
        Blade::anonymousComponentPath($themeComponents);

        // theme:: view namespace
        View::addNamespace('theme', $themeFolder);
    }
}
