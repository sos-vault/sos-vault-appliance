<?php

namespace App\Services;

use App\Models\Module;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PackageManager
{
    use ArchiveExtractsTarGz;

    public function __construct(
        private readonly ModuleInstaller $moduleInstaller,
        private readonly PatchInstaller $patchInstaller,
        private readonly GpgService $gpgService,
    ) {}

    /**
     * Install or update a package from the given archive path.
     * Accepts both plain .tar.gz and GPG-signed .tar.gz.gpg files.
     *
     * @throws RuntimeException
     */
    public function install(string $archivePath): Module
    {
        $isDecryptedTemp = false;

        if (str_ends_with($archivePath, '.gpg')) {
            $archivePath = $this->maybeDecrypt($archivePath);
            $isDecryptedTemp = true;
        }

        try {
            $manifest = $this->readManifest($archivePath);

            $type = $manifest['type'] ?? null;

            if ($type === 'module') {
                return $this->moduleInstaller->install($archivePath, $manifest);
            }

            if ($type === 'patch') {
                return $this->patchInstaller->install($archivePath, $manifest);
            }

            throw new RuntimeException("Unknown package type: {$type}");
        } finally {
            if ($isDecryptedTemp && file_exists($archivePath)) {
                unlink($archivePath);
            }
        }
    }

    /**
     * If the archive is a .gpg signed file, decrypt/verify it to a temp .tar.gz
     * and return that path. Otherwise return the path unchanged.
     *
     * @throws RuntimeException
     */
    private function maybeDecrypt(string $archivePath): string
    {
        if (! str_ends_with($archivePath, '.gpg')) {
            return $archivePath;
        }

        $gpgHome = config('modules.gpg_home');

        if (! $gpgHome || ! is_dir($gpgHome)) {
            throw new RuntimeException("GPG home directory not found: {$gpgHome}. Configure MODULES_GPG_HOME in .env.");
        }

        $tmpPath = sys_get_temp_dir().'/module-decrypt-'.uniqid().'.tar.gz';

        $this->gpgService->decrypt($archivePath, $tmpPath, $gpgHome);

        return $tmpPath;
    }

    public function remove(Module $module): void
    {
        if ($module->package_type === 'module') {
            $this->moduleInstaller->remove($module);
        }

        $module->delete();
    }

    /**
     * Extract and decode manifest.json from the archive without fully extracting it.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function readManifest(string $archivePath): array
    {
        $tmpDir = sys_get_temp_dir().'/manifest-read-'.uniqid();

        try {
            $this->extractTarGz($archivePath, $tmpDir);

            $found = $this->findManifestFile($tmpDir);

            if ($found === null) {
                throw new RuntimeException('manifest.json not found in package.');
            }

            $manifest = json_decode(file_get_contents($found), true);

            foreach (['type', 'id', 'name', 'version'] as $required) {
                if (empty($manifest[$required])) {
                    throw new RuntimeException("manifest.json is missing required field: {$required}");
                }
            }

            return $manifest;
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    private function findManifestFile(string $dir): ?string
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->getFilename() === 'manifest.json') {
                return $file->getPathname();
            }
        }

        return null;
    }
}
