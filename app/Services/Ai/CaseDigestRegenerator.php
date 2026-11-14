<?php

namespace App\Services\Ai;

use App\Providers\DataTools;
use App\Providers\VaultTools;
use Illuminate\Support\Facades\Log;

/**
 * (Re)generates the Mil case digest (.aiDigest.json) + summary files for a case
 * whose vault is open. Cases unpacked before the digest feature shipped have no
 * digest — summaryData()/getAiDigest() only run at unpack time — so Mil has no
 * grounding data for them. This regenerates on demand: lazily from
 * CaseContextBuilder on first analysis, and in bulk from the ai:backfill-digests
 * command. Reuses the same DataTools::summaryData() path used at unpack.
 */
class CaseDigestRegenerator
{
    /**
     * Ensure the case at $path has a digest. No-op when one already exists (unless
     * $force). Returns true when a digest was (re)generated. The caller must have
     * verified the vault is open. Never throws — a failure is logged and returns false.
     */
    public function ensure(VaultTools $vtools, string $vid, int $did, string $path, bool $force = false): bool
    {
        $file = config('ai.case_digest_file', '.aiDigest.json');

        if (! $force && is_file($path.'/'.$file)) {
            return false;
        }

        try {
            $dt = new DataTools($vtools, $vid, $did);
            $dt->summaryData(null);

            return is_file($path.'/'.$file);
        } catch (\Throwable $e) {
            Log::warning("AI digest regeneration failed for did={$did}: ".$e->getMessage());

            return false;
        }
    }
}
