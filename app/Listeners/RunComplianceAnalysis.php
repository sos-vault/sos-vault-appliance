<?php

namespace App\Listeners;

use App\Events\ComplianceAnalysisRequested;
use App\Models\SupportCase;
use App\Models\User;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\Compliance\ComplianceAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

// Runs on the queue so compliance analysis never blocks the upload/unpack
// request (mirrors EvaluateAlerts).
class RunComplianceAnalysis implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ComplianceAnalysisRequested $event): void
    {
        try {
            $user = User::find($event->userId);
            if (! $user) {
                return;
            }

            $case = SupportCase::find($event->cid);
            if (! $case) {
                return;
            }

            $vtools = new VaultTools($user, $event->vid);
            if (! $vtools->isOpen() || (int) $vtools->getVaultId() !== (int) $event->vid) {
                return;
            }

            $dtools = new DataTools($vtools, $event->vid, $event->did);

            app(ComplianceAnalysisService::class)->analyzeCase($case, $dtools, $vtools, 'unpack');
        } catch (\Throwable $e) {
            Log::warning('RunComplianceAnalysis listener failed: '.$e->getMessage());
        }
    }
}
