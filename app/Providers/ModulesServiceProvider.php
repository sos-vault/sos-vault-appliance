<?php

namespace App\Providers;

use App\Models\Module;
use Composer\Autoload\ClassLoader;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }

        $modules = Module::query()
            ->enabled()
            ->ofType('module')
            ->get();

        if ($modules->isEmpty()) {
            return;
        }

        /** @var ClassLoader $loader */
        $loader = require base_path('vendor/autoload.php');

        foreach ($modules as $module) {
            $id = $module->module_id;
            $ns = $this->moduleNamespace($id);
            $srcPath = base_path("modules/{$id}/src/");

            if (is_dir($srcPath)) {
                $loader->addPsr4("Modules\\{$ns}\\", $srcPath);
            }

            $viewsPath = base_path("modules/{$id}/resources/views/");
            if (is_dir($viewsPath)) {
                $this->loadViewsFrom($viewsPath, $id);
            }

            if ($module->provider && class_exists($module->provider)) {
                $this->app->register($module->provider);
            }
        }
    }

    private function moduleNamespace(string $id): string
    {
        return str($id)->studly()->toString();
    }
}
