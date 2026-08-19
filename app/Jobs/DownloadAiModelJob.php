<?php

namespace App\Jobs;

use App\Exceptions\ModelChecksumException;
use App\Models\User;
use App\Services\ModelProvisionService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downloads the local AI model in the background so a ~1.1 GB fetch never
 * blocks a web request. Triggered from the admin "Software Updates" page;
 * shares ModelProvisionService with the sos-vault:download-model command.
 *
 * Because the job runs in the queue worker (no HTTP session), operator
 * feedback is delivered as Filament DATABASE notifications addressed to the
 * admin who started the download — a periodic progress notice while the
 * transfer runs and a terminal success/failure notice — plus activity-log
 * events (AI_MODEL_START / AI_MODEL_DONE / AI_MODEL_FAIL / AI_MODEL_ABORT).
 */
class DownloadAiModelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /** Minimum seconds between "download progress" notifications. */
    private const PROGRESS_NOTIFY_INTERVAL = 120;

    /**
     * Cache key holding the live download state the admin "Software Updates" page
     * polls to render its progress bar. Shape:
     *   ['status' => 'downloading'|'done'|'failed', 'percent' => int,
     *    'downloaded' => int, 'total' => int, 'error' => ?string, 'at' => int].
     */
    public const STATE_CACHE_KEY = 'ai_model_download_state';

    /** Minimum seconds between cache progress writes (bar refresh is ~2s anyway). */
    private const PROGRESS_CACHE_INTERVAL = 2;

    public function __construct(public ?int $userId = null) {}

    public function handle(ModelProvisionService $models): void
    {
        if ($models->isInstalled()) {
            self::putState(['status' => 'done', 'percent' => 100]);

            return;
        }

        $user = $this->userId ? User::find($this->userId) : null;

        $this->event('AI_MODEL_START', 'SUCCESS', ['url' => $models->url()]);
        self::putState(['status' => 'downloading', 'percent' => 0, 'downloaded' => 0, 'total' => 0]);

        try {
            $models->download($this->progressCallback($user));
        } catch (ModelChecksumException $e) {
            $this->event('AI_MODEL_ABORT', 'FAILED', ['reason' => $e->getMessage()]);
            self::putState(['status' => 'failed', 'error' => $e->getMessage()], 600);
            $this->notifyFailure($user, 'AI model download aborted', $e->getMessage());
            Log::warning('AI model download aborted (checksum mismatch)', ['error' => $e->getMessage()]);

            throw $e;
        } catch (Throwable $e) {
            $this->event('AI_MODEL_FAIL', 'FAILED', ['reason' => $e->getMessage()]);
            self::putState(['status' => 'failed', 'error' => $e->getMessage()], 600);
            $this->notifyFailure($user, 'AI model download failed', $e->getMessage());
            Log::error('AI model download failed', ['error' => $e->getMessage()]);

            throw $e;
        }

        $this->event('AI_MODEL_DONE', 'SUCCESS', ['path' => $models->expectedPath()]);
        self::putState(['status' => 'done', 'percent' => 100], 600);
        Log::info('AI model downloaded', ['path' => $models->expectedPath()]);

        if ($user) {
            Notification::make()
                ->success()
                ->title('AI model ready')
                ->body('The download finished — the local AI assistant is now available.')
                ->sendToDatabase($user);
        }
    }

    /**
     * Combined progress callback: refreshes the cached state the Software Updates
     * page polls for its progress bar (throttled to PROGRESS_CACHE_INTERVAL) and
     * forwards to the throttled database-notification reporter.
     *
     * @return callable(int,int):void
     */
    public function progressCallback(?User $user): callable
    {
        $notify = $this->progressReporter($user);
        $lastCachedAt = 0;

        return function (int $downloaded, int $total) use ($notify, &$lastCachedAt): void {
            $now = time();
            if ($now - $lastCachedAt >= self::PROGRESS_CACHE_INTERVAL) {
                $lastCachedAt = $now;
                $percent = $total > 0 ? (int) floor($downloaded / $total * 100) : 0;
                self::putState([
                    'status' => 'downloading',
                    'percent' => max(0, min(99, $percent)),
                    'downloaded' => $downloaded,
                    'total' => $total,
                ]);
            }

            $notify($downloaded, $total);
        };
    }

    /**
     * Persist the download state the admin page polls. Terminal states use a
     * short TTL so a completed/failed bar clears itself; the in-progress state
     * lives long enough to outlast a slow transfer.
     *
     * @param  array<string, mixed>  $state
     */
    public static function putState(array $state, int $ttlSeconds = 7200): void
    {
        Cache::put(self::STATE_CACHE_KEY, $state + ['at' => time()], $ttlSeconds);
    }

    /** @return array<string, mixed>|null */
    public static function currentState(): ?array
    {
        $state = Cache::get(self::STATE_CACHE_KEY);

        return is_array($state) ? $state : null;
    }

    /**
     * Build the progress callback handed to ModelProvisionService::download().
     * Emits a "Model download progress X%" database notification at most once
     * every $intervalSeconds (default PROGRESS_NOTIFY_INTERVAL). No-op when the
     * triggering user is unknown, the server did not report a total size, or the
     * percentage is at the 0%/100% endpoints (the start flash and the completion
     * notice already cover those). The interval is injectable so tests can drive
     * the reporter without waiting on the wall clock.
     *
     * @return callable(int,int):void
     */
    public function progressReporter(?User $user, int $intervalSeconds = self::PROGRESS_NOTIFY_INTERVAL): callable
    {
        // Seed the throttle to "now" so the first progress notice lands one full
        // interval in, not immediately on top of the "download started" flash.
        $lastNotifiedAt = time();

        return function (int $downloaded, int $total) use ($user, $intervalSeconds, &$lastNotifiedAt): void {
            if (! $user) {
                return;
            }

            $now = time();
            if ($now - $lastNotifiedAt < $intervalSeconds) {
                return;
            }

            $percent = self::formatProgressPercent($downloaded, $total);
            if ($percent === null) {
                return;
            }

            $lastNotifiedAt = $now;

            Notification::make()
                ->title('AI model download in progress')
                ->body("Model download progress {$percent}%.")
                ->sendToDatabase($user);
        };
    }

    /**
     * Whole-percent download progress, or null when it should not be reported:
     * an unknown total (0) or the 0%/100% endpoints.
     */
    public static function formatProgressPercent(int $downloaded, int $total): ?int
    {
        if ($total <= 0) {
            return null;
        }

        $percent = (int) floor($downloaded / $total * 100);

        return ($percent <= 0 || $percent >= 100) ? null : $percent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(string $type, string $status, array $payload = []): void
    {
        addEvent($payload, $type, $status, 'ACTIVITY', 0, 0, $this->userId ?? 0, 0);
    }

    private function notifyFailure(?User $user, string $title, string $message): void
    {
        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title($title)
            ->body($message)
            ->sendToDatabase($user);
    }
}
