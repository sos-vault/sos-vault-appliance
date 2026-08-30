<?php

use App\Models\Module;
use App\Services\PatchInstaller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// ---------------------------------------------------------------------------
// Helper: build a .tar.gz patch package
// ---------------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $manifest
 * @param  array<string, string>  $files  relative-path => content
 */
function buildPatchPackage(string $path, array $manifest, array $files = []): void
{
    $id = $manifest['id'];
    $tmpDir = sys_get_temp_dir()."/patch-build-{$id}-".uniqid();
    $pkgDir = "{$tmpDir}/{$id}";
    mkdir($pkgDir, 0755, true);

    file_put_contents("{$pkgDir}/manifest.json", json_encode($manifest));

    foreach ($files as $rel => $content) {
        $abs = "{$pkgDir}/{$rel}";
        File::ensureDirectoryExists(dirname($abs));
        file_put_contents($abs, $content);
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

it('copies patch files to their destination and creates a DB record', function () {
    $targetFile = base_path('storage/test-patch-target-'.uniqid().'.txt');
    $srcRelative = 'storage/patch-src.txt';

    $path = sys_get_temp_dir().'/test-patch-'.uniqid().'.tar.gz';
    $manifest = [
        'type' => 'patch',
        'id' => 'test-copy-patch',
        'name' => 'Copy Patch',
        'version' => '1.0.0',
        'files' => [
            ['src' => $srcRelative, 'dest' => 'storage/'.basename($targetFile)],
        ],
        'post_install' => [],
    ];

    buildPatchPackage($path, $manifest, [$srcRelative => 'patched content']);

    $module = app(PatchInstaller::class)->install($path, $manifest);

    expect($module)->toBeInstanceOf(Module::class)
        ->and($module->package_type)->toBe('patch')
        ->and($module->module_id)->toBe('test-copy-patch');

    expect(file_exists($targetFile))->toBeTrue()
        ->and(file_get_contents($targetFile))->toBe('patched content');

    @unlink($targetFile);
    @unlink($path);
});

it('backs up an existing file before overwriting it', function () {
    $target = base_path('storage/test-backup-target-'.uniqid().'.txt');
    file_put_contents($target, 'original content');
    $srcRelative = 'storage/new.txt';
    $destRelative = 'storage/'.basename($target);

    $path = sys_get_temp_dir().'/test-backup-patch-'.uniqid().'.tar.gz';
    $manifest = [
        'type' => 'patch',
        'id' => 'test-backup-patch',
        'name' => 'Backup Patch',
        'version' => '2.0.0',
        'files' => [
            ['src' => $srcRelative, 'dest' => $destRelative],
        ],
        'post_install' => [],
    ];

    buildPatchPackage($path, $manifest, [$srcRelative => 'new content']);

    app(PatchInstaller::class)->install($path, $manifest);

    $backupDir = storage_path('app/private/patch-backups/test-backup-patch-2.0.0');
    $backupFile = $backupDir.'/'.str_replace('/', '_', $destRelative);

    expect(file_exists($backupFile))->toBeTrue()
        ->and(file_get_contents($backupFile))->toBe('original content');

    @unlink($target);
    @unlink($path);
    File::deleteDirectory($backupDir);
});

it('runs post_install artisan commands', function () {
    $srcRelative = 'storage/dummy.txt';
    $destRelative = 'storage/dummy-dest-'.uniqid().'.txt';

    $path = sys_get_temp_dir().'/test-postinstall-'.uniqid().'.tar.gz';
    $manifest = [
        'type' => 'patch',
        'id' => 'test-postinstall',
        'name' => 'Post Install Patch',
        'version' => '1.0.0',
        'files' => [
            ['src' => $srcRelative, 'dest' => $destRelative],
        ],
        'post_install' => ['optimize:clear'],
    ];

    buildPatchPackage($path, $manifest, [$srcRelative => 'content']);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear', ['--force' => true]);

    app(PatchInstaller::class)->install($path, $manifest);

    @unlink(base_path($destRelative));
    @unlink($path);
});

it('rolls back files when a post_install command throws', function () {
    $target = base_path('storage/test-rollback-'.uniqid().'.txt');
    file_put_contents($target, 'before patch');
    $destRelative = 'storage/'.basename($target);
    $srcRelative = 'storage/patch.txt';

    $path = sys_get_temp_dir().'/test-rollback-patch-'.uniqid().'.tar.gz';
    $manifest = [
        'type' => 'patch',
        'id' => 'test-rollback',
        'name' => 'Rollback Patch',
        'version' => '1.0.0',
        'files' => [
            ['src' => $srcRelative, 'dest' => $destRelative],
        ],
        'post_install' => ['failing-command'],
    ];

    buildPatchPackage($path, $manifest, [$srcRelative => 'patched content']);

    Artisan::shouldReceive('call')
        ->with('failing-command', ['--force' => true])
        ->andThrow(new RuntimeException('command failed'));

    expect(fn () => app(PatchInstaller::class)->install($path, $manifest))
        ->toThrow(RuntimeException::class, 'command failed');

    expect(file_get_contents($target))->toBe('before patch');

    @unlink($target);
    @unlink($path);
});
