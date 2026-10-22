<?php

namespace App\Services;

use App\Models\Module;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PatchInstaller
{
    use ArchiveExtractsTarGz;

    /**
     * Install a patch package.
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws RuntimeException
     */
    public function install(string $archivePath, array $manifest): Module
    {
        $id = $manifest['id'];
        $version = $manifest['version'];
        $files = $manifest['files'] ?? [];
        $postInstall = $manifest['post_install'] ?? [];

        $extractDir = storage_path("app/private/module-uploads/{$id}-extract");
        $backupDir = storage_path("app/private/patch-backups/{$id}-{$version}");

        File::ensureDirectoryExists($extractDir);
        File::ensureDirectoryExists($backupDir);

        $this->extractTarGz($archivePath, $extractDir);

        // Flatten single top-level directory if present.
        $items = glob("{$extractDir}/*");
        if (count($items) === 1 && is_dir($items[0])) {
            $inner = $items[0];
            $tmp = $extractDir.'_tmp';
            File::moveDirectory($inner, $tmp);
            File::deleteDirectory($extractDir);
            File::moveDirectory($tmp, $extractDir);
        }

        $backedUp = [];

        try {
            foreach ($files as $entry) {
                $src = $entry['src'];
                $dest = $entry['dest'];

                // If dest ends with '/', treat as directory → preserve filename.
                if (str_ends_with($dest, '/')) {
                    $dest = $dest.basename($src);
                }

                $destAbsolute = base_path($dest);
                $srcAbsolute = "{$extractDir}/{$src}";

                if (! File::exists($srcAbsolute)) {
                    throw new RuntimeException("Source file not found in patch: {$src}");
                }

                // Back up existing file before overwriting.
                if (File::exists($destAbsolute)) {
                    $backupPath = "{$backupDir}/".str_replace('/', '_', $dest);
                    File::copy($destAbsolute, $backupPath);
                    $backedUp[] = ['dest' => $destAbsolute, 'backup' => $backupPath];
                }

                File::ensureDirectoryExists(dirname($destAbsolute));
                File::copy($srcAbsolute, $destAbsolute);
            }

            foreach ($postInstall as $command) {
                Artisan::call($command, ['--force' => true]);
            }
        } catch (\Throwable $e) {
            $this->rollback($backedUp);
            File::deleteDirectory($extractDir);
            throw $e;
        }

        File::deleteDirectory($extractDir);

        return Module::updateOrCreate(
            ['module_id' => $id],
            [
                'package_type' => 'patch',
                'name' => $manifest['name'],
                'version' => $version,
                'description' => $manifest['description'] ?? null,
                'author' => $manifest['author'] ?? null,
                'provider' => null,
                'tool_name' => null,
                'tool_slug' => null,
                'tool_icon' => null,
                'is_enabled' => true,
                'installed_at' => now(),
            ]
        );
    }

    /**
     * @param  array<int, array{dest: string, backup: string}>  $backedUp
     */
    private function rollback(array $backedUp): void
    {
        foreach ($backedUp as $entry) {
            if (File::exists($entry['backup'])) {
                File::copy($entry['backup'], $entry['dest']);
            }
        }
    }
}
