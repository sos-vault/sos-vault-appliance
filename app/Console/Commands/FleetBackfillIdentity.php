<?php

namespace App\Console\Commands;

use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use App\Services\Fleet\FleetIdentityBackfiller;
use Illuminate\Console\Command;

/**
 * Backfills the host identity (machine_id + hostname) for existing cases. Cases
 * unpacked before the View Fleet feature shipped only carry the filename-derived
 * host, so they group by filename on the fleet page until backfilled.
 *
 * Vaults are LUKS-encrypted and only readable while mounted, so this can only
 * process cases whose vault is CURRENTLY open; closed vaults are reported and
 * skipped. (The sosbrowser page also backfills lazily on open, so this command
 * is a pre-warm, not the only path.)
 */
class FleetBackfillIdentity extends Command
{
    protected $signature = 'fleet:backfill-identity
        {--case= : Limit to a single SupportCase id}
        {--force : Re-read the identity even when already populated}
        {--dry-run : Report what would be updated without writing}';

    protected $description = 'Populate machine_id/hostname on existing cases whose vault is currently open';

    public function handle(FleetIdentityBackfiller $backfiller): int
    {
        $query = SupportCase::query();
        if ($id = $this->option('case')) {
            $query->where('id', $id);
        }

        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');

        $updated = $already = $closed = $unresolved = 0;

        foreach ($query->cursor() as $case) {
            if (! $force && ! empty($case->machine_id) && ! empty($case->hostname)) {
                $already++;

                continue;
            }

            $did = (int) $case->file_id;
            $vault = $case->vault_id ? Vault::find($case->vault_id) : null;
            $owner = $vault ? User::find($vault->owner) : null;

            if ($did === 0 || ! $owner) {
                $unresolved++;

                continue;
            }

            $vtools = new VaultTools($owner);
            if (! $vtools->isOpen()) {
                $closed++;

                continue;
            }

            $dir = $vtools->getDirById($did);
            if (! $dir) {
                $unresolved++;

                continue;
            }
            $path = rtrim($vtools->getMountPoint(), '/').'/'.$dir->name;

            if ($dry) {
                $this->line("would backfill: case {$case->id} (did {$did})");
                $updated++;

                continue;
            }

            if ($backfiller->ensure($vtools, $vtools->getVaultId(), $did, $path, $case, $force)) {
                $updated++;
            } else {
                $unresolved++;
            }
        }

        $this->info("Fleet identity — updated: {$updated}, already present: {$already}, skipped (vault closed): {$closed}, unresolved: {$unresolved}");

        return self::SUCCESS;
    }
}
