<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Source of truth for the modules the portal may offer to customers.
 *
 * Reads each module's manifest.json from a root directory (default: the repo's
 * modules/ folder). Each manifest contributes id, name, description, version,
 * provider, and a required_features list that the license ACL enforces at
 * download time.
 */
class ModuleCatalog
{
    public function __construct(
        private readonly string $root,
    ) {}

    /**
     * Return every module whose manifest.json parses cleanly. Each entry is a
     * normalized array: id, name, description, version, provider,
     * required_features, archive_path (nullable).
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if (! is_dir($this->root)) {
            return [];
        }

        $modules = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $this->root.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($dir)) {
                continue;
            }
            $manifest = $this->readManifest($dir);
            if ($manifest !== null) {
                $modules[] = $manifest;
            }
        }

        return $modules;
    }

    /** Look up a single module by its manifest id. */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $module) {
            if ($module['id'] === $id) {
                return $module;
            }
        }

        return null;
    }

    private function readManifest(string $dir): ?array
    {
        $path = $dir.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || empty($data['id'])) {
            Log::warning("ModuleCatalog: ignoring malformed manifest at {$path}");

            return null;
        }

        $required = $data['required_features'] ?? [];
        if (! is_array($required)) {
            $required = [];
        }
        $required = array_values(array_filter($required, 'is_string'));

        $archive = $dir.DIRECTORY_SEPARATOR.$data['id'].'.tar.gz';

        return [
            'id' => (string) $data['id'],
            'name' => (string) ($data['name'] ?? $data['id']),
            'description' => (string) ($data['description'] ?? ''),
            'version' => (string) ($data['version'] ?? '0.0.0'),
            'provider' => (string) ($data['provider'] ?? ''),
            'required_features' => $required,
            'archive_path' => is_file($archive) ? $archive : null,
        ];
    }
}
