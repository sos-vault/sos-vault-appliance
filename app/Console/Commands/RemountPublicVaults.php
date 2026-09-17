<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RemountPublicVaults extends Command
{
    protected $signature = 'vault:remount-public';

    protected $description = 'Remount all vaults flagged as always_open (run after server reboot)';

    public function handle(): int
    {
        // The appliance boot service runs this on start-up, which on a FRESH
        // install happens BEFORE the installer's migrate step — the sqlite DB is
        // an empty file with no schema yet. Without this guard the query below
        // throws "no such table: vaults", the service's ExecStartPost retry loop
        // burns all 30 iterations (~60s) before giving up, and the installer
        // appears to hang on Step 11. A missing table simply means "nothing to
        // remount" — succeed immediately so the loop exits on the first try.
        if (! Schema::hasTable((new Vault)->getTable())) {
            $this->info('Vaults table not present yet — nothing to remount.');

            return self::SUCCESS;
        }

        $vaults = Vault::where('always_open', true)->get();

        if ($vaults->isEmpty()) {
            $this->info('No always_open vaults found.');

            return self::SUCCESS;
        }

        foreach ($vaults as $vault) {
            $owner = User::find($vault->owner);

            if (! $owner) {
                $this->warn("Vault {$vault->id}: owner not found, skipping.");

                continue;
            }

            $vtools = new VaultTools($owner, $vault->id);

            if ($vtools->isOpen()) {
                $this->info("Vault {$vault->id} ({$owner->username}): already open.");

                continue;
            }

            $this->info("Vault {$vault->id} ({$owner->username}): mounting...");

            if ($vtools->OpenVault()) {
                $this->info("Vault {$vault->id}: mounted successfully.");
                Log::info("vault:remount-public — vault {$vault->id} remounted.");
                addEvent(
                    (object) ['message' => "vault:remount-public remounted vault {$vault->id} ({$owner->username})"],
                    'SCHEDULER', 'SUCCESS', 'ACTIVITY', 0, $vault->id, $owner->id, $owner->id
                );
            } else {
                $this->error("Vault {$vault->id}: failed to mount.");
                Log::error("vault:remount-public — vault {$vault->id} failed to mount.");
                addEvent(
                    (object) ['message' => "vault:remount-public failed to remount vault {$vault->id} ({$owner->username})"],
                    'SCHEDULER', 'FAILED', 'ACTIVITY', 0, $vault->id, $owner->id, $owner->id
                );
            }
        }

        return self::SUCCESS;
    }
}
