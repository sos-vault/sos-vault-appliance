<?php

namespace App\Console\Commands;

use App\Services\ModelProvisionService;
use Illuminate\Console\Command;

/**
 * Downloads the local AI model (GGUF weights) from HuggingFace.
 *
 * Invoked by the installer (Step 10) on first boot and available ad-hoc for an
 * operator who deferred the download. The admin UI uses DownloadAiModelJob,
 * which shares ModelProvisionService with this command.
 */
class DownloadAiModel extends Command
{
    protected $signature = 'sos-vault:download-model {--force : Re-download even if the model is already present}';

    protected $description = 'Download and verify the local AI model (GGUF weights) from HuggingFace';

    public function handle(ModelProvisionService $models): int
    {
        if ($models->isInstalled() && ! $this->option('force')) {
            $this->info('Model already present at '.$models->expectedPath().' — use --force to re-download.');

            return self::SUCCESS;
        }

        $this->info('Downloading model from '.$models->url());
        $this->comment('This is a ~1.1 GB download and may take a while…');

        try {
            $models->download();
        } catch (\Throwable $e) {
            $this->error('Model download failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Model installed at '.$models->expectedPath());

        return self::SUCCESS;
    }
}
