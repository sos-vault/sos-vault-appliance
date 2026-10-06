<?php

use App\Models\Module;
use App\Services\ModuleInstaller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// ---------------------------------------------------------------------------
// Helper: build a .tar.gz module package
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $manifest
 * @param  array<string>  $extraFiles  paths relative to the package root
 */
function buildModulePackage(string $path, array $manifest, array $extraFiles = []): void
{
    $id = $manifest['id'];
    $tmpDir = sys_get_temp_dir()."/mod-build-{$id}-".uniqid();
    $pkgDir = "{$tmpDir}/{$id}";
    mkdir($pkgDir, 0755, true);

    file_put_contents("{$pkgDir}/manifest.json", json_encode($manifest));

    foreach ($extraFiles as $rel) {
        $abs = "{$pkgDir}/{$rel}";
        File::ensureDirectoryExists(dirname($abs));
        file_put_contents($abs, '<?php // stub');
    }

    $tarPath = substr($path, 0, -3);
    $archive = new PharData($tarPath);
    $archive->buildFromDirectory($tmpDir);
    $compressed = $archive->compress(Phar::GZ);
    unset($archive, $compressed);
    unlink($tarPath);

    File::deleteDirectory($tmpDir);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

afterEach(function () {
    // Clean up any module directories created during tests.
    foreach (glob(base_path('modules/test-*')) as $dir) {
        File::deleteDirectory($dir);
    }
});

it('extracts the module to modules/{id}/ and creates a DB record', function () {
    $path = sys_get_temp_dir().'/test-module-'.uniqid().'.tar.gz';
    $manifest = [
        'type' => 'module',
        'id' => 'test-extract',
        'name' => 'Test Extract',
        'version' => '1.0.0',
        'author' => 'Tester',
        'provider' => null,
        'tool' => ['name' => 'Extract Tool', 'slug' => 'extract', 'icon' => 'phosphor-star-duotone'],
    ];

    buildModulePackage($path, $manifest);

    $module = app(ModuleInstaller::class)->install($path, $manifest);

    expect($module)->toBeInstanceOf(Module::class)
        ->and($module->module_id)->toBe('test-extract')
        ->and($module->name)->toBe('Test Extract')
        ->and($module->version)->toBe('1.0.0')
        ->and($module->package_type)->toBe('module')
        ->and($module->tool_slug)->toBe('extract');

    expect(Module::where('module_id', 'test-extract')->exists())->toBeTrue();
    expect(is_dir(base_path('modules/test-extract')))->toBeTrue();

    @unlink($path);
});

it('runs migrations when database/migrations directory exists in the package', function () {
    $path = sys_get_temp_dir().'/test-migrations-'.uniqid().'.tar.gz';
    $manifest = [
        'type' => 'module',
        'id' => 'test-migrations',
        'name' => 'Migration Module',
        'version' => '1.0.0',
        'provider' => null,
        'tool' => null,
    ];

    buildModulePackage($path, $manifest, [
        'database/migrations/2099_01_01_create_dummy_table.php',
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', ['--path' => 'modules/test-migrations/database/migrations', '--force' => true]);

    app(ModuleInstaller::class)->install($path, $manifest);

    @unlink($path);
});

it('updates an existing DB record on reinstall', function () {
    Module::factory()->create([
        'module_id' => 'test-update',
        'version' => '1.0.0',
        'name' => 'Old Name',
    ]);

    $path = sys_get_temp_dir().'/test-update-'.uniqid().'.tar.gz';
    $manifest = [
        'type' => 'module',
        'id' => 'test-update',
        'name' => 'New Name',
        'version' => '2.0.0',
        'provider' => null,
        'tool' => null,
    ];

    buildModulePackage($path, $manifest);

    app(ModuleInstaller::class)->install($path, $manifest);

    $updated = Module::where('module_id', 'test-update')->first();

    expect($updated->version)->toBe('2.0.0')
        ->and($updated->name)->toBe('New Name')
        ->and(Module::where('module_id', 'test-update')->count())->toBe(1);

    @unlink($path);
});

it('deletes module directory and public assets on remove', function () {
    $module = Module::factory()->create(['module_id' => 'test-remove']);

    $moduleDir = base_path('modules/test-remove');
    $publicDir = public_path('modules/test-remove');
    File::ensureDirectoryExists($moduleDir);
    File::ensureDirectoryExists($publicDir);

    app(ModuleInstaller::class)->remove($module);

    expect(is_dir($moduleDir))->toBeFalse()
        ->and(is_dir($publicDir))->toBeFalse();
});
