<?php

namespace App\Services\Fleet;

use App\Models\SupportCase;
use App\Providers\DataTools;
use App\Providers\VaultTools;
use Illuminate\Support\Facades\Log;

/**
 * Backfills the host identity (machine_id + real hostname) on a SupportCase.
 * Cases unpacked before the View Fleet feature shipped only carry the
 * filename-derived `host`; the real identity lives in the per-report
 * .hostData.json cache inside the (LUKS-encrypted) vault, so it can only be
 * read while the vault is open. Runs lazily from the sosbrowser page and in
 * bulk from the fleet:backfill-identity command.
 */
class FleetIdentityBackfiller
{
    /**
     * Ensure $case carries machine_id/hostname. No-op when both are already set
     * (unless $force). Returns true when the case row was updated. The caller
     * must have verified the vault is open. Never throws — a failure is logged
     * and returns false.
     */
    public function ensure(VaultTools $vtools, string $vid, int $did, string $path, SupportCase $case, bool $force = false): bool
    {
        if (! $force && ! empty($case->machine_id) && ! empty($case->hostname)) {
            return false;
        }

        try {
            $hostinfo = null;
            $cache = $path.'/.hostData.json';
            if (is_file($cache)) {
                $hostinfo = json_decode(file_get_contents($cache));
            }
            if (! is_object($hostinfo) || ! property_exists($hostinfo, 'machineid')) {
                // getHostData() returns the cache verbatim when the file exists,
                // so a pre-machineid cache must be dropped to force a re-parse.
                is_file($cache) && @unlink($cache);
                $dt = new DataTools($vtools, $vid, $did);
                $hostinfo = $dt->getHostData();
            }

            $dirty = false;
            if (! empty($hostinfo->machineid) && $case->machine_id !== $hostinfo->machineid) {
                $case->machine_id = $hostinfo->machineid;
                $dirty = true;
            }
            if (! empty($hostinfo->hostname) && $case->hostname !== $hostinfo->hostname) {
                $case->hostname = $hostinfo->hostname;
                $dirty = true;
            }
            $dirty && $case->save();

            return $dirty;
        } catch (\Throwable $e) {
            Log::warning("Fleet identity backfill failed for did={$did}: ".$e->getMessage());

            return false;
        }
    }
}
