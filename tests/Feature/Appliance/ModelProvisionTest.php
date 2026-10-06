<?php

/**
 * #3 — the local AI model (GGUF weights) is NOT shipped in the deb; it is
 * downloaded from HuggingFace on first boot / from the admin UI and verified
 * against a pinned sha256. ModelProvisionService is the single implementation
 * shared by the installer command and the queued job.
 */

use App\Exceptions\ModelChecksumException;
use App\Services\ModelProvisionService;
use Illuminate\Support\Facades\Http;

const FAKE_MODEL_BYTES = 'FAKE-MODEL-BYTES';

beforeEach(function () {
    $this->modelDir = sys_get_temp_dir().'/sosv-model-'.bin2hex(random_bytes(4));
    @mkdir($this->modelDir, 0775, true);

    config([
        'ai.local.model_dir' => $this->modelDir,
        'ai.local.model_filename' => 'test.gguf',
        'ai.local.model_url' => 'https://example.test/test.gguf',
        'ai.local.model_sha256' => hash('sha256', FAKE_MODEL_BYTES),
    ]);
});

afterEach(function () {
    foreach (glob($this->modelDir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->modelDir);
});

it('reports not installed when the model file is absent', function () {
    expect(app(ModelProvisionService::class)->isInstalled())->toBeFalse();
});

it('downloads, verifies sha256, and installs the model', function () {
    Http::fake(['*' => Http::response(FAKE_MODEL_BYTES)]);

    $svc = app(ModelProvisionService::class);
    $svc->download();

    expect($svc->isInstalled())->toBeTrue()
        ->and(file_get_contents($svc->expectedPath()))->toBe(FAKE_MODEL_BYTES);
});

it('rejects a checksum mismatch and leaves no partial file behind', function () {
    config(['ai.local.model_sha256' => str_repeat('0', 64)]);
    Http::fake(['*' => Http::response(FAKE_MODEL_BYTES)]);

    $svc = app(ModelProvisionService::class);

    // A checksum mismatch is a distinct, integrity-abort failure (the job
    // reports it as "aborted", not "failed").
    expect(fn () => $svc->download())->toThrow(ModelChecksumException::class);
    expect($svc->isInstalled())->toBeFalse();
    expect(is_file($svc->expectedPath().'.part'))->toBeFalse();
});

it('throws when no model url is configured', function () {
    config(['ai.local.model_url' => '']);

    expect(fn () => app(ModelProvisionService::class)->download())
        ->toThrow(RuntimeException::class);
});

it('refuses to download when no sha256 is configured (fail closed)', function () {
    config(['ai.local.model_sha256' => '']);
    Http::fake(['*' => Http::response(FAKE_MODEL_BYTES)]);

    $svc = app(ModelProvisionService::class);

    // Must bail BEFORE fetching — an unverifiable model is never installed.
    expect(fn () => $svc->download())->toThrow(RuntimeException::class);
    expect($svc->isInstalled())->toBeFalse();
    Http::assertNothingSent();
});

it('command skips the download when the model is already present', function () {
    file_put_contents($this->modelDir.'/test.gguf', 'already-here');
    Http::fake(); // any HTTP call would make the assertion below fail

    $this->artisan('sos-vault:download-model')
        ->expectsOutputToContain('already present')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('command downloads the model when missing', function () {
    Http::fake(['*' => Http::response(FAKE_MODEL_BYTES)]);

    $this->artisan('sos-vault:download-model')->assertSuccessful();

    expect(app(ModelProvisionService::class)->isInstalled())->toBeTrue();
});

it('command reports failure on a checksum mismatch', function () {
    config(['ai.local.model_sha256' => str_repeat('0', 64)]);
    Http::fake(['*' => Http::response(FAKE_MODEL_BYTES)]);

    $this->artisan('sos-vault:download-model')->assertFailed();
});
