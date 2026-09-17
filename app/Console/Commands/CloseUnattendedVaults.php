<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vault;
use App\Providers\VaultTools;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class CloseUnattendedVaults extends Command
{
    protected $signature = 'vault:close-unattended';

    protected $description = 'Close OPEN vaults whose owner has dropped their session (skips always_open vaults)';

    public function handle(): int
    {
        $lifetime = config()->get('session.lifetime') * 60;
        $cutoff = (int) date('U') - $lifetime;

        $users = static::usersWithUnattendedVaults($cutoff);

        if ($users->isNotEmpty()) {
            Log::info('Closing unattended vaults...');

            // these are users that have dropped their sessions. Close their vaults
            foreach ($users as $user) {
                $vtools = null;
                $vtools = new VaultTools($user);

                if ($vtools && $vtools->vaultExists()) {
                    $vid = $vtools->getVaultId();
                    if (! $vtools->closeVault(1)) {
                        Log::error("{$user->username}'s vault could not be closed");
                        addEvent(
                            (object) ['message' => "vault:close-unattended could not close {$user->username}'s vault"],
                            'SCHEDULER', 'FAILED', 'ACTIVITY', 0, $vid, $user->id, $user->id
                        );

                        continue;
                    }
                    Log::info("{$user->username}'s vault CLOSED");
                    addEvent(
                        (object) ['message' => "vault:close-unattended closed {$user->username}'s vault"],
                        'SCHEDULER', 'SUCCESS', 'ACTIVITY', 0, $vid, $user->id, $user->id
                    );

                    // PEND: logout the user
                }
            }
        }

        return self::SUCCESS;
    }

    public static function usersWithUnattendedVaults(int $cutoff): Collection
    {
        $ownerIds = Vault::where('status', 'OPEN')
            ->where('always_open', false)
            ->pluck('owner');

        return User::whereIn('id', $ownerIds)
            ->where('last_activity', '<', $cutoff)
            ->get();
    }
}
