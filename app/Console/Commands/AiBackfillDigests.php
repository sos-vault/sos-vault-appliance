<?php

namespace App\Console\Commands;

use App\Models\SupportCase;
use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use App\Services\Ai\CaseDigestRegenerator;
use Illuminate\Console\Command;

/**
 * Backfills the Mil AI digest (.aiDigest.json) for existing cases. Cases unpacked
 * before the digest feature shipped have none, so Mil can't analyse them until one
 * exists. This generates them in bulk.
 *
 * Vaults are LUKS-encrypted and only readable while mounted, so this can only
 * process cases whose vault is CURRENTLY open; closed vaults are reported and
 * skipped. (CaseContextBuilder also generates the digest lazily on first analysis,
 * so this command is a pre-warm, not the only path.)
 */
class AiBackfillDigests extends Command
{
    protected $signature = 'ai:backfill-digests
        {--case= : Limit to a single SupportCase id}
        {--force : Regenerate even when a digest already exists}
        {--dry-run : Report what would be generated without writing}';

    protected $description = 'Generate the Mil AI digest for existing cases whose vault is currently open';

    public function handle(CaseDigestRegenerator $regen): int
    {
        $query = SupportCase::query();
        if ($id = $this->option('case')) {
            $query->where('id', $id);
        }

        $digestFile = config('ai.case_digest_file', '.aiDigest.json');
        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');

        $generated = $already = $closed = $unresolved = 0;

        foreach ($query->cursor() as $case) {
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

            if (! $force && is_file($path.'/'.$digestFile)) {
                $already++;

                continue;
            }

            if ($dry) {
                $this->line("would generate: case {$case->id} (did {$did})");
                $generated++;

                continue;
            }

            if ($regen->ensure($vtools, $vtools->getVaultId(), $did, $path, $force)) {
                $generated++;
            } else {
                $unresolved++;
            }
        }

        $this->info("Digests — generated: {$generated}, already present: {$already}, skipped (vault closed): {$closed}, unresolved: {$unresolved}");

        return self::SUCCESS;
    }
}
