<?php

namespace App\Console\Commands;

use App\Models\LocalLicense;
use Illuminate\Console\Command;

/**
 * Open-core daily check: emit a single LICENSE_EXPIRED event the first time a
 * LocalLicense row is observed past its expires_at. Uses the
 * expiry_event_logged_at column to deduplicate so subsequent runs do not
 * re-emit for the same row.
 *
 * Only runs on appliance; SaaS skips via routes/console.php.
 */
class CheckLicenseExpiry extends Command
{
    protected $signature = 'sos-vault:check-license-expiry';

    protected $description = 'Emit LICENSE_EXPIRED events for any newly-expired LocalLicense rows.';

    public function handle(): int
    {
        if (! isAppliance()) {
            $this->info('sos-vault:check-license-expiry skipped on non-appliance build.');

            return 0;
        }

        $emitted = 0;
        $candidates = LocalLicense::query()
            ->where('expires_at', '<=', now())
            ->whereNull('expiry_event_logged_at')
            ->get();

        foreach ($candidates as $license) {
            addEvent(
                [
                    'uuid' => $license->uuid,
                    'expires_at' => $license->expires_at?->toIso8601String(),
                    'customer_id' => $license->customer_id,
                ],
                'LICENSE_EXPIRED',
                'EXPIRED',
                'ACTIVITY',
                0,
                0,
                $license->uploaded_by ?? 0,
                0
            );

            $license->update(['expiry_event_logged_at' => now()]);
            $emitted++;
        }

        $this->info("Emitted {$emitted} LICENSE_EXPIRED event(s).");

        return 0;
    }
}
