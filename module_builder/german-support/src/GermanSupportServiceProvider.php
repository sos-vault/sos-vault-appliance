<?php

namespace Modules\GermanSupport;

use Illuminate\Support\ServiceProvider;

class GermanSupportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Add German to the supported locales list (used by the language selector UI).
        config(['app.supported_locales' => array_merge(
            config('app.supported_locales', []),
            ['de' => 'Deutsch']
        )]);

        // Register the German translation files with Laravel's FileLoader.
        // addPath() makes the loader search this directory for strings without a namespace,
        // so __('nav.dashboard') resolves from de/nav.php in this path.
        $langPath = base_path('modules/german-support/resources/lang');

        if (is_dir($langPath)) {
            $this->app->make('translator')->getLoader()->addPath($langPath);
        }
    }
}
