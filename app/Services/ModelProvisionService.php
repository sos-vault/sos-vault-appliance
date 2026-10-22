<?php

namespace App\Services;

use App\Exceptions\ModelChecksumException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads and verifies the local AI model (GGUF weights) on demand.
 *
 * The ~1.1 GB model is NOT shipped in the deb. It is fetched from HuggingFace
 * into config('ai.local.model_dir') — bind-mounted into the llama.cpp container
 * — on first boot (installer Step 10, via sos-vault:download-model) or from the
 * admin "Software Updates" page (queued DownloadAiModelJob). One implementation
 * serves both paths so behaviour can't drift.
 */
class ModelProvisionService
{
    public function modelDir(): string
    {
        return (string) config('ai.local.model_dir', base_path('models'));
    }

    public function filename(): string
    {
        return (string) config('ai.local.model_filename', 'model.gguf');
    }

    public function url(): string
    {
        return (string) config('ai.local.model_url', '');
    }

    public function expectedSha256(): string
    {
        return strtolower((string) config('ai.local.model_sha256', ''));
    }

    public function expectedPath(): string
    {
        return rtrim($this->modelDir(), '/').'/'.$this->filename();
    }

    /**
     * Whether the model file is already in place. Cheap (existence + non-empty),
     * so it is safe to call on every page render — the sha256 is only checked
     * after a fresh download, never on the hot path.
     */
    public function isInstalled(): bool
    {
        $path = $this->expectedPath();

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Stream the model to disk, verify its sha256, then atomically move it into
     * place. The partial download is removed on any failure, so a crash never
     * leaves a corrupt or half-written .gguf where llama.cpp would load it.
     *
     * When given, $onProgress is invoked repeatedly during the transfer as
     * $onProgress(int $downloadedBytes, int $totalBytes) — $totalBytes is 0
     * until the server's Content-Length is known. Callers throttle/format it
     * (the job turns it into periodic "X% downloaded" notifications).
     *
     * @param  callable(int,int):void|null  $onProgress
     *
     * @throws ModelChecksumException when the downloaded file fails sha256 verification
     * @throws RuntimeException on any other download failure
     */
    public function download(?callable $onProgress = null): void
    {
        $url = $this->url();
        if ($url === '') {
            throw new RuntimeException('No model_url configured (config/ai.php → local.model_url).');
        }

        // Fail closed: the sha256 is the only integrity guarantee for a ~1.1 GB
        // binary fetched over the network. A blank pin would otherwise silently
        // accept whatever bytes the server (or a MITM / compromised mirror)
        // returned, so refuse to download rather than install unverified weights.
        $expected = $this->expectedSha256();
        if ($expected === '') {
            throw new RuntimeException('No model_sha256 configured (config/ai.php → local.model_sha256) — refusing to install an unverified model.');
        }

        $dir = $this->modelDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create model directory: {$dir}");
        }
        if (! is_writable($dir)) {
            throw new RuntimeException("Model directory is not writable: {$dir} (chown to the container uid).");
        }

        $final = $this->expectedPath();
        $tmp = $final.'.part';
        @unlink($tmp);

        try {
            // Guzzle sink streams the body straight to disk — the 1.1 GB body is
            // never held in memory. Generous timeout for a large file on a slow link.
            // The Guzzle 'progress' option fires as bytes arrive; forward the
            // download counters to the caller's callback when one was supplied.
            $request = Http::timeout(3600)
                ->connectTimeout(30)
                ->sink($tmp);

            if ($onProgress !== null) {
                $request->withOptions([
                    'progress' => function ($downloadTotal, $downloadedBytes) use ($onProgress): void {
                        $onProgress((int) $downloadedBytes, (int) $downloadTotal);
                    },
                ]);
            }

            $response = $request->get($url);

            if (! $response->successful()) {
                throw new RuntimeException("Download failed: HTTP {$response->status()} from {$url}");
            }

            $actual = strtolower((string) hash_file('sha256', $tmp));
            if ($actual !== $expected) {
                throw new ModelChecksumException("Checksum mismatch: expected {$expected}, got {$actual}.");
            }

            if (! @rename($tmp, $final)) {
                throw new RuntimeException("Could not move downloaded model into place: {$final}");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
