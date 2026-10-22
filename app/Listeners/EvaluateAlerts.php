<?php

namespace App\Listeners;

use App\Events\AlertsEvaluationRequested;
use App\Models\SupportCase;
use App\Models\User;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use App\Services\AlertEvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

// Runs on the queue so alert evaluation never blocks the upload/unpack
// request (mirrors FixSosHtml). Evaluates every enabled alert owned by the
// case's vault, regardless of which vault member created each alert.
class EvaluateAlerts implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(AlertsEvaluationRequested $event): void
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

            app(AlertEvaluationService::class)->evaluateVaultForCase($case, $dtools, $vtools);
        } catch (\Throwable $e) {
            Log::warning('EvaluateAlerts listener failed: '.$e->getMessage());
        }
    }
}
