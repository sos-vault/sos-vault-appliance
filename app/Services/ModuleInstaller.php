<?php

namespace App\Services;

use App\Models\Module;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ModuleInstaller
{
    use ArchiveExtractsTarGz;

    /**
     * Install a module package.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws RuntimeException
     */
    public function install(string $archivePath, array $manifest): Module
    {
        $id = $manifest['id'];
        $moduleDir = base_path("modules/{$id}");

        $this->extract($archivePath, $moduleDir);
        $this->publishAssets($id, $moduleDir);
        $this->runMigrations($id, $moduleDir);

        return Module::updateOrCreate(
            ['module_id' => $id],
            [
                'package_type' => 'module',
                'name' => $manifest['name'],
                'version' => $manifest['version'],
                'description' => $manifest['description'] ?? null,
                'author' => $manifest['author'] ?? null,
                'provider' => $manifest['provider'] ?? null,
                'tool_name' => $manifest['tool']['name'] ?? null,
                'tool_slug' => $manifest['tool']['slug'] ?? null,
                'tool_icon' => $manifest['tool']['icon'] ?? null,
                'is_enabled' => true,
                'installed_at' => now(),
            ]
        );
    }

    public function remove(Module $module): void
    {
        $moduleDir = base_path("modules/{$module->module_id}");
        $publicDir = public_path("modules/{$module->module_id}");

        if (File::isDirectory($moduleDir)) {
            File::deleteDirectory($moduleDir);
        }

        if (File::isDirectory($publicDir)) {
            File::deleteDirectory($publicDir);
        }
    }

    private function extract(string $archivePath, string $moduleDir): void
    {
        File::ensureDirectoryExists($moduleDir);

        $this->extractTarGz($archivePath, $moduleDir);

        // If the archive has a single top-level directory, flatten it.
        $items = glob("{$moduleDir}/*");
        if (count($items) === 1 && is_dir($items[0])) {
            $inner = $items[0];
            $tmp = $moduleDir.'_tmp_extract';
            File::moveDirectory($inner, $tmp);
            File::deleteDirectory($moduleDir);
            File::moveDirectory($tmp, $moduleDir);
        }
    }

    private function publishAssets(string $id, string $moduleDir): void
    {
        $publicSrc = "{$moduleDir}/public";

        if (File::isDirectory($publicSrc)) {
            $publicDest = public_path("modules/{$id}");
            File::ensureDirectoryExists($publicDest);
            File::copyDirectory($publicSrc, $publicDest);
        }
    }

    private function runMigrations(string $id, string $moduleDir): void
    {
        $migrationsPath = "{$moduleDir}/database/migrations";

        if (File::isDirectory($migrationsPath)) {
            Artisan::call('migrate', [
                '--path' => "modules/{$id}/database/migrations",
                '--force' => true,
            ]);
        }
    }
}
