<?php

namespace App\Listeners;

use App\Events\FixSosHtmlRequested;
use App\Models\User;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

// Retro-fixes an already-unpacked report's sos_reports/sos.html when its case is
// opened. Runs on the queue so it never blocks case load, and is idempotent
// (DataTools::fixSosHtml short-circuits on the fixed marker), so duplicate opens
// are harmless.
class FixSosHtml implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(FixSosHtmlRequested $event): void
    {
        try {
            $user = User::find($event->userId);
            if (! $user) {
                return;
            }

            $vtools = new VaultTools($user, $event->vid);
            if (! $vtools->isOpen() || (int) $vtools->getVaultId() !== (int) $event->vid) {
                // Owner's vault isn't mounted (e.g. a shared/public-case viewer) —
                // skip; it'll be fixed at next upload or when the owner opens it.
                return;
            }

            $dtools = new DataTools($vtools, $event->vid, $event->did);
            $dtools->fixSosHtml($event->cid);
        } catch (\Throwable $e) {
            Log::warning('FixSosHtml listener failed: '.$e->getMessage());
        }
    }
}
