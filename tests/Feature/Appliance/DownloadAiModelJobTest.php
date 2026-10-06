<?php

/**
 * The queued AI-model download reports back to the admin who started it:
 *   - activity-log events  (AI_MODEL_START / _DONE / _FAIL / _ABORT)
 *   - a periodic "Model download progress X%" database notification
 *   - a terminal success / failure database notification
 * Because the job runs in the queue worker (no HTTP session) the operator
 * feedback has to be database notifications, not session flashes.
 */

use App\Exceptions\ModelChecksumException;
use App\Jobs\DownloadAiModelJob;
use App\Models\Sysevent;
use App\Models\User;
use App\Services\ModelProvisionService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const JOB_MODEL_BYTES = 'JOB-MODEL-BYTES';

beforeEach(function () {
    config(['product.type' => 'appliance']);
    $this->seed(RolesTableSeeder::class);

    $this->modelDir = sys_get_temp_dir().'/sosv-job-'.bin2hex(random_bytes(4));
    @mkdir($this->modelDir, 0775, true);
    config([
        'ai.local.model_dir' => $this->modelDir,
        'ai.local.model_filename' => 'job.gguf',
        'ai.local.model_url' => 'https://example.test/job.gguf',
        'ai.local.model_sha256' => hash('sha256', JOB_MODEL_BYTES),
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

afterEach(function () {
    foreach (glob($this->modelDir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->modelDir);
});

function runDownloadJob(?int $userId): void
{
    (new DownloadAiModelJob($userId))->handle(app(ModelProvisionService::class));
}

it('records start + done events and notifies the admin on success', function () {
    Http::fake(['*' => Http::response(JOB_MODEL_BYTES)]);

    runDownloadJob($this->admin->id);

    expect(Sysevent::where('type', 'AI_MODEL_START')->where('status', 'SUCCESS')->exists())->toBeTrue();
    expect(Sysevent::where('type', 'AI_MODEL_DONE')->where('status', 'SUCCESS')->exists())->toBeTrue();

    $notifications = $this->admin->notifications()->get();
    expect($notifications)->toHaveCount(1);
    expect(json_encode($notifications->first()->data))->toContain('AI model ready');
});

it('records an abort event and danger notification on a checksum mismatch', function () {
    config(['ai.local.model_sha256' => str_repeat('0', 64)]);
    Http::fake(['*' => Http::response(JOB_MODEL_BYTES)]);

    expect(fn () => runDownloadJob($this->admin->id))->toThrow(ModelChecksumException::class);

    expect(Sysevent::where('type', 'AI_MODEL_ABORT')->where('status', 'FAILED')->exists())->toBeTrue();
    expect(json_encode($this->admin->notifications()->first()->data))->toContain('aborted');
});

it('records a fail event and danger notification on a download error', function () {
    config(['ai.local.model_url' => '']); // RuntimeException, not a checksum abort

    expect(fn () => runDownloadJob($this->admin->id))->toThrow(RuntimeException::class);

    expect(Sysevent::where('type', 'AI_MODEL_FAIL')->where('status', 'FAILED')->exists())->toBeTrue();
    expect(Sysevent::where('type', 'AI_MODEL_ABORT')->exists())->toBeFalse();
    expect(json_encode($this->admin->notifications()->first()->data))->toContain('failed');
});

it('does nothing and records no event when the model is already installed', function () {
    file_put_contents($this->modelDir.'/job.gguf', 'already-here');
    Http::fake();

    runDownloadJob($this->admin->id);

    expect(Sysevent::whereIn('type', ['AI_MODEL_START', 'AI_MODEL_DONE'])->count())->toBe(0);
    expect($this->admin->notifications()->count())->toBe(0);
    Http::assertNothingSent();
});

it('formatProgressPercent returns whole percents and null at the endpoints', function () {
    expect(DownloadAiModelJob::formatProgressPercent(0, 0))->toBeNull();       // unknown total
    expect(DownloadAiModelJob::formatProgressPercent(0, 100))->toBeNull();     // 0%
    expect(DownloadAiModelJob::formatProgressPercent(100, 100))->toBeNull();   // 100%
    expect(DownloadAiModelJob::formatProgressPercent(500, 1000))->toBe(50);
    expect(DownloadAiModelJob::formatProgressPercent(337, 1000))->toBe(33);    // floor
});

it('progress reporter sends a throttled percent notification to the admin', function () {
    // interval 0 => the first call fires immediately (the wall-clock throttle is
    // satisfied), so we can assert the notification without waiting 2 minutes.
    $reporter = (new DownloadAiModelJob($this->admin->id))->progressReporter($this->admin, 0);

    $reporter(250, 1000);

    expect($this->admin->notifications()->count())->toBe(1);
    expect(json_encode($this->admin->notifications()->first()->data))->toContain('Model download progress 25%');
});

it('progress reporter is a no-op when the triggering user is unknown', function () {
    $reporter = (new DownloadAiModelJob(null))->progressReporter(null, 0);

    $reporter(250, 1000);

    expect(DB::table('notifications')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Progress state cache — polled by the Software Updates page for the bar.
// ---------------------------------------------------------------------------

it('writes a done state to the cache after a successful download', function () {
    Cache::forget(DownloadAiModelJob::STATE_CACHE_KEY);
    Http::fake(['*' => Http::response(JOB_MODEL_BYTES)]);

    runDownloadJob($this->admin->id);

    $state = DownloadAiModelJob::currentState();
    expect($state['status'])->toBe('done');
    expect($state['percent'])->toBe(100);
});

it('writes a failed state to the cache on a download error', function () {
    Cache::forget(DownloadAiModelJob::STATE_CACHE_KEY);
    config(['ai.local.model_url' => '']);

    expect(fn () => runDownloadJob($this->admin->id))->toThrow(RuntimeException::class);

    $state = DownloadAiModelJob::currentState();
    expect($state['status'])->toBe('failed');
    expect($state['error'])->not->toBe('');
});

it('progress callback writes a throttled downloading state to the cache', function () {
    Cache::forget(DownloadAiModelJob::STATE_CACHE_KEY);

    $callback = (new DownloadAiModelJob($this->admin->id))->progressCallback($this->admin);
    $callback(250, 1000);

    $state = DownloadAiModelJob::currentState();
    expect($state['status'])->toBe('downloading');
    expect($state['percent'])->toBe(25);
    expect($state['downloaded'])->toBe(250);
    expect($state['total'])->toBe(1000);
});
