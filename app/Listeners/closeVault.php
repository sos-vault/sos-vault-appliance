<?php

namespace App\Listeners;

// use IlluminateAuthEventsLogout;
use App\Models\UserToken;
use App\Providers\VaultTools;
use Illuminate\Auth\Events\Logout as IlluminateAuthEventsLogout;
use Illuminate\Support\Facades\Log;

class closeVault
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(IlluminateAuthEventsLogout $event): void
    {
        // Respect the APP_NOVAULTS=TRUE test flag — the same guard initializeVault uses.
        if (config('app.vaultsDisabled') === 'TRUE') {
            return;
        }

        $ini = microtime(true);
        $user = auth()->user();

        if (! $user) {
            // the session timed out
            return;
        }

        if ($user->hasRole('Self-hosted')) {
            Log::info("closeVault: skipping Self-hosted {$user->email} — no vault");

            return;
        }

        // Fail closed like initializeVault: with no usable svault0 key, building
        // VaultTools below constructs new Encrypter('', …) and throws. There is
        // nothing open to close when the keyring is empty, and this path also runs
        // from initializeVault's own auth()->logout() when it refuses a login —
        // without this guard that logout re-throws the very 500 the refusal exists
        // to prevent.
        if (strlen(getSvaultKey('svault0')) !== 32) {
            Log::warning("closeVault: svault0 keyring not loaded — skipping vault teardown for {$user->email}");

            return;
        }

        $lock = "/var/tmp/.closeVault_{$user->id}.lock";

        if (! file_exists($lock)) {
            file_put_contents($lock, "\n");

            $vtools = new VaultTools($user);

            if ($vtools && $vtools->vaultExists()) {
                // Only close if no other group members are still using this vault
                $vaultId = $vtools->getVaultId();
                $lastUser = initializeVault::deregisterActiveUser($vaultId, $user->id);

                if ($lastUser) {
                    if (! $vtools->closeVault()) {
                        Log::error("user $user->username vault could not be closed");
                    }
                } else {
                    Log::info("vault {$vaultId} kept open — other users still active");
                }
                file_exists($lock) && unlink($lock);

                // Update Tokens statistics
                $tokens = new UserToken;
                $tokens->resetSessionTokens($user->id);

                $end = microtime(true);
                Log::info(sprintf('closeVault took %d s', $end - $ini));

                return;
            }

            unlink($lock);
        }

        if (file_exists($lock)) {
            if (date('U') - stat($lock)[9] > 20) {
                unlink($lock);
            }
        }

        $end = microtime(true);
        Log::info(sprintf('closeVault took %d s', $end - $ini));

    }
}
