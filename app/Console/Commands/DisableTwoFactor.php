<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

/**
 * Break-glass recovery for a locked-out account — e.g. an airgapped admin whose
 * server clock drifted out of the TOTP window and who has no recovery codes
 * left. Run inside the app container: `docker exec ... php artisan 2fa:disable
 * admin@example.com`.
 */
class DisableTwoFactor extends Command
{
    protected $signature = '2fa:disable {user : Email, username or id of the account}';

    protected $description = 'Break-glass: disable two-factor auth for a locked-out account';

    public function handle(TwoFactorService $twoFactor): int
    {
        $needle = (string) $this->argument('user');

        $user = User::where('email', $needle)
            ->orWhere('username', $needle)
            ->when(ctype_digit($needle), fn ($q) => $q->orWhere('id', (int) $needle))
            ->first();

        if (! $user) {
            $this->error("No user matching '{$needle}'.");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled()) {
            $this->info("Two-factor is already disabled for {$user->email}.");

            return self::SUCCESS;
        }

        $twoFactor->disable($user);

        addEvent(
            (object) ['message' => 'two-factor disabled via break-glass CLI', 'email' => $user->email],
            'CHG_PASS',
            'SUCCESS',
            'ACTIVITY',
            0,
            0,
            $user->id,
            $user->id
        );

        $this->info("Two-factor disabled for {$user->email}. They can re-enroll from Settings → Security.");

        return self::SUCCESS;
    }
}
