<?php

namespace App\Listeners;

// use IlluminateAuthEventsLogin;
use App\Models\Group;
use App\Models\UserToken;
use App\Models\Vault;
use App\Providers\VaultTools;
use Illuminate\Auth\Events\Login as IlluminateAuthEventsLogin;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Wave\Plan;
use Wave\Setting;

class initializeVault
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
    public function handle(IlluminateAuthEventsLogin $event): void
    {
        $ini = microtime(true);
        $user = $event->user ?? auth()->user();

        if (! $user) {
            return;
        }

        if (! $user->hasVerifiedEmail()) {
            Log::info("initializeVault: skipping unverified user {$user->email}");

            return;
        }

        if ($user->hasRole('suspended')) {
            Log::info("initializeVault: skipping suspended user {$user->email}");

            return;
        }

        if ($user->hasRole('Self-hosted')) {
            Log::info("initializeVault: skipping Self-hosted {$user->email} — no vault");

            return;
        }

        // Fail closed when the kernel keyring has no svault0 key. Every vault path
        // below builds a VaultTools, whose constructor feeds getSvaultKey('svault0')
        // into a new Encrypter — an empty key throws "Unsupported cipher or incorrect
        // key length" and the login POST 500s. Refuse the login with a friendly
        // message instead (see assertVaultKeyringLoaded).
        self::assertVaultKeyringLoaded($user);

        // Appliance: the admin ALWAYS has a personal LUKS vault (sosreports are
        // private and must be encrypted at rest) — provisioned on first login,
        // opened thereafter, regardless of licence. Members only ever open the
        // shared group vault — no personal vault provisioning, no lazy group
        // creation. If a member has no group or the group has no vault yet,
        // log + skip; the admin must finish wiring them up from the Groups panel.
        if (isAppliance()) {
            if ($user->hasRole('admin')) {
                if (! Vault::where('owner', $user->id)->exists()) {
                    // Admin personal vault defaults to 10 GB — keep it large
                    // (do NOT lower to the 500 MB group-vault default).
                    $sizeMb = (int) (Setting::get('appliance.default_vault_size_mb') ?: 10240);
                    VaultTools::createPersonalVault($user, $sizeMb);
                } elseif (config('app.vaultsDisabled') !== 'TRUE') {
                    $vtools = new VaultTools($user);
                    if ($vtools->vaultExists() && (! $vtools->isOpen() || ! $vtools->isMounted())) {
                        $vtools->openVault();
                    }
                }

                $vid = (int) (Vault::where('owner', $user->id)->value('id') ?? 0);
                self::registerActiveUser($vid, $user->id);

                return;
            }

            if (! $user->group_id || ! $user->group?->vault_id) {
                Log::warning("initializeVault[appliance]: {$user->email} has no group vault — skipping");

                return;
            }

            if (config('app.vaultsDisabled') === 'TRUE') {
                self::registerActiveUser((int) $user->group->vault_id, $user->id);

                return;
            }

            $vtools = new VaultTools($user);
            if ($vtools->vaultExists() && (! $vtools->isOpen() || ! $vtools->isMounted())) {
                $vtools->openVault();
            }
            self::registerActiveUser($vtools->getVaultId(), $user->id);

            return;
        }

        // Respect the APP_NOVAULTS=TRUE test flag — guard prevents any LUKS/OS operations.
        // Placed after the unverified-user check so that check's log message still fires in tests.
        if (config('app.vaultsDisabled') === 'TRUE') {
            return;
        }

        $lock = "/var/tmp/.initializeVault_{$user->id}.lock";

        if ($user->onTrial()) {
            Log::warning("$user->name is on trial period");
            if ($user->daysLeftOnTrial() <= 0) {
                // no need to send notification that the trial is expired as the ui has lots of indications
                Log::warning("Trial period for $user->name has expired");

                return;
            }
            $daysLeft = $user->daysLeftOnTrial();
            Log::warning("Trial period for $user->name still has $daysLeft days left");
        }

        // Auto-create group for Team / Enterprise managers who don't have one yet
        if ($user->hasRole(['Team', 'Enterprise']) && ! $user->group_id && ! Group::where('owner_id', $user->id)->exists()) {
            $roleName = $user->roles->first()?->name;
            $plan = Plan::whereEnglishName($roleName)->first();
            $group = Group::create([
                'name' => $user->name."'s Group",
                'owner_id' => $user->id,
                'plan_id' => $plan?->id,
                'max_members' => $roleName === 'Enterprise' ? 20 : 8,
            ]);
            if (! $user->group_id) {
                $user->update(['group_id' => $group->id]);
            }
            Log::info("initializeVault: auto-created group for {$user->email}");
        }

        if (! file_exists($lock)) {
            file_put_contents($lock, "\n");

            $vtools = new VaultTools($user);

            if ($vtools && $vtools->vaultExists()) {
                // open it
                if (! $vtools->openVault()) {
                    // trhow event and error
                    Log::error("user $user->username vault could not be openned");
                }
                self::registerActiveUser($vtools->getVaultId(), $user->id);
                file_exists($lock) && unlink($lock);

                $end = microtime(true);
                Log::info(sprintf('initializeVault took %d s', $end - $ini));

                return;
            }

            if (! $vtools->createVault()) {
                // trhow event and error
                Log::error("user $user->username vault could not be created");
                file_exists($lock) && unlink($lock);

                $end = microtime(true);
                Log::info(sprintf('initializeVault took %d s', $end - $ini));

                return;
            }
            self::registerActiveUser($vtools->getVaultId(), $user->id);

            // create user tokens record
            $qty = intval(str_replace(' M', '', getPlanTokens($user)));

            $tokens = UserToken::firstOrNew(['user_id' => $user->id]);
            $tokens->save();

            $itokens = $tokens->input_tokens_available + ($qty * pow(10, 6));
            $otokens = $tokens->output_tokens_available + ($qty * pow(10, 3));

            $tokens->update([
                'input_tokens_available' => $itokens,
                'output_tokens_available' => $otokens,
                'total_tokens_available' => $itokens + $otokens,
            ]);

            file_exists($lock) && unlink($lock);
        }

        if (file_exists($lock)) {
            if (date('U') - stat($lock)[9] > 200) {
                file_exists($lock) && unlink($lock);
            }
        }

        $end = microtime(true);
        Log::info(sprintf('initializeVault took %d s', $end - $ini));
    }

    /**
     * Refuse the login cleanly when the kernel keyring has no usable svault0 key.
     *
     * The Login event fires *after* the session guard has already authenticated
     * the user, so we log them back out and throw a ValidationException — Laravel
     * redisplays the login form with a friendly message instead of the raw 500
     * ("Unsupported cipher or incorrect key length") the empty-key Encrypter emits.
     * vaultsDisabled (tests / APP_NOVAULTS) short-circuits: those paths never open
     * a vault, so no keyring is required.
     */
    private static function assertVaultKeyringLoaded($user): void
    {
        if (config('app.vaultsDisabled') === 'TRUE' || strlen(getSvaultKey('svault0')) === 32) {
            return;
        }

        Log::error("initializeVault: svault0 keyring not loaded — refusing login for {$user->email}");
        auth()->logout();

        throw ValidationException::withMessages([
            'email' => __('The secure vault is temporarily unavailable. Please try again in a moment, or contact your administrator if the problem persists.'),
        ]);
    }

    /**
     * Seconds since last system boot — entries older than this are stale.
     */
    private static function uptimeSeconds(): float
    {
        if (file_exists('/proc/uptime')) {
            return (float) explode(' ', file_get_contents('/proc/uptime'))[0];
        }

        // Non-Linux fallback: treat anything older than 24 h as stale
        return 86400.0;
    }

    /**
     * Parse a "userId:timestamp" line. Returns [userId, timestamp] or null if malformed / stale.
     */
    private static function parseLine(string $line): ?array
    {
        $parts = explode(':', trim($line), 2);
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }
        $ts = (int) $parts[1];
        $age = time() - $ts;
        // Entry pre-dates the last boot → stale
        if ($age > self::uptimeSeconds()) {
            return null;
        }

        return [(int) $parts[0], $ts];
    }

    /**
     * Record that $userId is now actively using vault $vaultId.
     */
    public static function registerActiveUser(int $vaultId, int $userId): void
    {
        if (! $vaultId) {
            return;
        }
        $file = "/var/tmp/.vault_users_{$vaultId}";
        $fp = fopen($file, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            $lines = array_filter(explode("\n", stream_get_contents($fp)));
            $entries = [];
            foreach ($lines as $line) {
                $parsed = self::parseLine($line);
                if ($parsed && $parsed[0] !== $userId) {
                    $entries[$parsed[0]] = $line; // keep other valid users
                }
            }
            $entries[$userId] = "{$userId}:".time(); // upsert current user
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, implode("\n", array_values($entries)));
            flock($fp, LOCK_UN);
        }
        is_resource($fp) && fclose($fp);
    }

    /**
     * Remove $userId from the active-user list for vault $vaultId.
     * Returns true if no other live users remain (safe to close), false otherwise.
     */
    public static function deregisterActiveUser(int $vaultId, int $userId): bool
    {
        if (! $vaultId) {
            return true;
        }
        $file = "/var/tmp/.vault_users_{$vaultId}";
        if (! file_exists($file)) {
            return true;
        }
        $fp = fopen($file, 'c+');
        if (! $fp || ! flock($fp, LOCK_EX)) {
            is_resource($fp) && fclose($fp);

            return true;
        }
        $lines = array_filter(explode("\n", stream_get_contents($fp)));
        $remaining = [];
        foreach ($lines as $line) {
            $parsed = self::parseLine($line);
            // Keep only valid, non-stale entries for other users
            if ($parsed && $parsed[0] !== $userId) {
                $remaining[] = $line;
            }
        }
        ftruncate($fp, 0);
        rewind($fp);
        if (count($remaining) > 0) {
            fwrite($fp, implode("\n", $remaining));
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        if (count($remaining) === 0) {
            @unlink($file);
        }

        return count($remaining) === 0;
    }
}
