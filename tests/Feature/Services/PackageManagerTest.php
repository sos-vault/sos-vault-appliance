<?php

use App\Models\Module;
use App\Services\GpgService;
use App\Services\ModuleInstaller;
use App\Services\PackageManager;
use App\Services\PatchInstaller;
use Illuminate\Support\Facades\File;

// ---------------------------------------------------------------------------
// Archive builder helper
// ---------------------------------------------------------------------------

/**
 * Build a minimal .tar.gz in $path with a manifest.json inside a top-level dir.
 *
 * @param  array<string, mixed>  $manifest
 */
function buildPackageTarGz(string $path, array $manifest, string $topDir = 'test-pkg'): void
{
    $tmpDir = sys_get_temp_dir().'/pkg-build-'.uniqid();
    mkdir("{$tmpDir}/{$topDir}", 0755, true);
    file_put_contents("{$tmpDir}/{$topDir}/manifest.json", json_encode($manifest));

    $tarPath = substr($path, 0, -3); // strip .gz → .tar
    $archive = new PharData($tarPath);
    $archive->buildFromDirectory($tmpDir);
    $compressed = $archive->compress(Phar::GZ);
    unset($archive, $compressed);

    if (file_exists($tarPath)) {
        unlink($tarPath);
    }
    // PharData writes <name>.tar.gz alongside the .tar
    if (file_exists($tarPath.'.gz') && $tarPath.'.gz' !== $path) {
        rename($tarPath.'.gz', $path);
    }

    File::deleteDirectory($tmpDir);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('throws RuntimeException when manifest.json is missing', function () {
    $tmpDir = sys_get_temp_dir().'/pkg-no-manifest-'.uniqid();
    mkdir("{$tmpDir}/pkg", 0755, true);
    file_put_contents("{$tmpDir}/pkg/readme.txt", 'no manifest');

    $tarPath = sys_get_temp_dir().'/no-manifest-'.uniqid().'.tar';
    $archive = new PharData($tarPath);
    $archive->buildFromDirectory($tmpDir);
    $compressed = $archive->compress(Phar::GZ);
    unset($archive, $compressed);
    unlink($tarPath);
    File::deleteDirectory($tmpDir);

    $manager = app(PackageManager::class);
    expect(fn () => $manager->install($tarPath.'.gz'))
        ->toThrow(RuntimeException::class, 'manifest.json not found');

    @unlink($tarPath.'.gz');
});

it('throws RuntimeException for an unknown package type', function () {
    $path = sys_get_temp_dir().'/bad-type-'.uniqid().'.tar.gz';
    buildPackageTarGz($path, [
        'type' => 'unknown',
        'id' => 'x',
        'name' => 'X',
        'version' => '1.0.0',
    ]);

    expect(fn () => app(PackageManager::class)->install($path))
        ->toThrow(RuntimeException::class, 'Unknown package type');

    @unlink($path);
});

it('throws RuntimeException when a required manifest field is missing', function () {
    $path = sys_get_temp_dir().'/missing-field-'.uniqid().'.tar.gz';
    buildPackageTarGz($path, [
        'type' => 'module',
        'id' => 'test',
        // 'name' intentionally missing
        'version' => '1.0.0',
    ]);

    expect(fn () => app(PackageManager::class)->install($path))
        ->toThrow(RuntimeException::class, 'missing required field');

    @unlink($path);
});

it('removes a module record from the database', function () {
    $module = Module::factory()->create(['module_id' => 'removable-mod']);

    $mockInstaller = Mockery::mock(ModuleInstaller::class);
    $mockInstaller->shouldReceive('remove')->once();

    $manager = new PackageManager($mockInstaller, app(PatchInstaller::class), app(GpgService::class));
    $manager->remove($module);

    expect(Module::where('module_id', 'removable-mod')->exists())->toBeFalse();
});

it('skips ModuleInstaller remove for patch packages', function () {
    $module = Module::factory()->patch()->create(['module_id' => 'core-patch-1']);

    $mockInstaller = Mockery::mock(ModuleInstaller::class);
    $mockInstaller->shouldNotReceive('remove');

    $manager = new PackageManager($mockInstaller, app(PatchInstaller::class), app(GpgService::class));
    $manager->remove($module);

    expect(Module::where('module_id', 'core-patch-1')->exists())->toBeFalse();
});
