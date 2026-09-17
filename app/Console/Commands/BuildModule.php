<?php

namespace App\Console\Commands;

use App\Services\GpgService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PharData;
use RuntimeException;

class BuildModule extends Command
{
    protected $signature = 'module:build
                            {id : Module ID (directory name under module_builder/)}
                            {--no-sign : Skip GPG signing and output a plain .tar.gz}
                            {--gpg-home= : Override GPG home directory for signing}
                            {--passphrase= : GPG key passphrase (if omitted you will be prompted)}';

    protected $description = 'Package a module from module_builder/{id}/ into a signed .tar.gz.gpg archive';

    public function __construct(private readonly GpgService $gpgService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $id = $this->argument('id');
        $sourceDir = base_path("module_builder/{$id}");

        if (! is_dir($sourceDir)) {
            $this->error("Module source directory not found: {$sourceDir}");

            return self::FAILURE;
        }

        $manifestPath = "{$sourceDir}/manifest.json";

        if (! file_exists($manifestPath)) {
            $this->error("manifest.json not found in {$sourceDir}");

            return self::FAILURE;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $version = $manifest['version'] ?? '0.0.0';

        $distDir = base_path('module_builder/dist');
        File::ensureDirectoryExists($distDir);

        $baseName = "{$id}-{$version}";
        $tarPath = "{$distDir}/{$baseName}.tar";
        $tarGzPath = "{$tarPath}.gz";

        $this->info("Building package for module: {$id} v{$version}");

        // Build .tar.gz using PharData
        $this->buildTarGz($sourceDir, $id, $tarPath, $tarGzPath);

        if ($this->option('no-sign')) {
            $this->info("Package built (unsigned): {$tarGzPath}");

            return self::SUCCESS;
        }

        // GPG-sign the .tar.gz
        // Priority: --gpg-home option > GPG_HOME_BUILD env > project .gnupg > $HOME/.gnupg
        $gpgHome = $this->option('gpg-home')
            ?: getenv('GPG_HOME_BUILD')
            ?: (is_dir(base_path('.gnupg')) ? base_path('.gnupg') : null)
            ?: (getenv('HOME') ? getenv('HOME').'/.gnupg' : null);

        if (! $gpgHome || ! is_dir($gpgHome)) {
            $this->error("GPG home not found: {$gpgHome}. Import your private key into the project .gnupg/, set GPG_HOME_BUILD in .env, or use --gpg-home.");
            @unlink($tarGzPath);

            return self::FAILURE;
        }

        $gpgOutputPath = "{$distDir}/{$baseName}.tar.gz.gpg";

        $passphrase = $this->option('passphrase') ?? $this->secret('GPG key passphrase (press Enter if none)') ?? '';

        try {
            $this->info("Signing with GPG home: {$gpgHome}");
            $this->gpgService->sign($tarGzPath, $gpgOutputPath, $gpgHome, $passphrase);
        } catch (RuntimeException $e) {
            $this->error("GPG signing failed: {$e->getMessage()}");
            @unlink($tarGzPath);

            return self::FAILURE;
        } finally {
            // Remove the intermediate unsigned archive
            if (file_exists($tarGzPath)) {
                unlink($tarGzPath);
            }
        }

        $this->info("Signed package created: {$gpgOutputPath}");

        return self::SUCCESS;
    }

    private function buildTarGz(string $sourceDir, string $moduleId, string $tarPath, string $tarGzPath): void
    {
        // PharData requires the .tar path (without .gz) first
        if (file_exists($tarPath)) {
            unlink($tarPath);
        }

        if (file_exists($tarGzPath)) {
            unlink($tarGzPath);
        }

        // Build from a temp directory so the archive contains a top-level {id}/ directory
        $tmpDir = sys_get_temp_dir()."/mod-build-{$moduleId}-".uniqid();
        $pkgDir = "{$tmpDir}/{$moduleId}";
        mkdir($pkgDir, 0755, true);

        File::copyDirectory($sourceDir, $pkgDir);

        $archive = new PharData($tarPath);
        $archive->buildFromDirectory($tmpDir);
        $archive->compress(\Phar::GZ);

        unset($archive);

        // Remove the uncompressed .tar
        if (file_exists($tarPath)) {
            unlink($tarPath);
        }

        File::deleteDirectory($tmpDir);
    }
}
