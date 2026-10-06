<?php

namespace App\Listeners;

use App\Events\AdjustVault;
use App\Events\ExpandVault;
use App\Events\ShrinkVault;
use App\Models\UserToken;
use App\Providers\VaultTools;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

// ShouldBeUnique intentionally removed: without uniqueId() it creates a global
// lock that blocks ALL users' resize jobs simultaneously.  Per-user serialisation
// is already handled by the /var/tmp/.resizeVault_{id}.lock file below.
class ResizeVault implements ShouldQueue
{
    use InteractsWithQueue;

    // Create the event listener.
    public function __construct() {}

    // Handle the event.
    public function handle(ExpandVault|ShrinkVault|AdjustVault $event): void
    {

        // throw new \Exception("Error Processing the job", 1);

        $olditokens = 0;
        $newitokens = 0;
        $type = 'error';
        $message = '';
        $vtools = null;
        $units = pow(1024, 2);  // used for messages only

        $user = $event->data['user'];
        $size = $event->data['size'];
        $plan = $event->data['plan'];

        if ($user->locale && array_key_exists($user->locale, config('app.supported_locales', []))) {
            App::setLocale($user->locale);
        }

        if ($event instanceof AdjustVault) {
            $newrole_id = $event->data['newrole_id'];
            $oldrole_id = $event->data['oldrole_id'];

            $tokens = UserToken::where(['user_id' => $user->id])->first();
            $olditokens = $tokens?->input_tokens_available ?? 0;
        }

        $vid = 0;
        $owner = $user->id;
        $group = $user->id;
        $hsize = Number::filesize($size, 2);

        // AdjustVault calculates its own size internally; allow size=0 for tokens-only plan changes.
        if (! $user || (! $size && ! ($event instanceof AdjustVault))) {
            return;
        }

        $ini = microtime(true);
        $lock = "/var/tmp/.resizeVault_{$user->id}.lock";

        if (! file_exists($lock)) {
            file_put_contents($lock, "\n");

            $vtools = new VaultTools($user);
            if (! $vtools) {
                // Original: "No vault associated to {$user->username}."
                $message = __('notifications.vault_no_associated', ['username' => $user->username]);
                Log::error($message);
                notifyUser($user, $message, $type);
                file_exists($lock) && unlink($lock);

                return;
            }

            if (! $vtools->vaultExists()) {
                // Original: "No vault found for {$user->username}."
                $message = __('notifications.vault_not_found', ['username' => $user->username]);
                Log::error($message);
                notifyUser($user, $message, $type);
                file_exists($lock) && unlink($lock);

                return;
            }

            if (! $vtools->isOpen()) {
                // Original: "Vault is not open for {$user->username}."
                $message = __('notifications.vault_not_open', ['username' => $user->username]);
                Log::error($message);
                notifyUser($user, $message, $type);
                file_exists($lock) && unlink($lock);

                return;
            }

            $oldSize = $vtools->currentSize();

            // VaultTools needs the size to be in MB and the plan is defined as GB
            $size = $size / pow(1024, 2);

            $vid = $vtools->getVaultId();
            $payload = (object) [
                'description' => $plan->description,
                'plan_id' => $plan->id,
                'message' => '',
            ];

            if ($event instanceof ExpandVault) {
                if (! $vtools->expandVault($size, $payload)) {
                    // Original: "Vault expanded to $hsize failed."
                    $message = __('notifications.vault_expand_failed', ['size' => $hsize]);
                } else {
                    $type = 'success';
                    // Original: "Vault expanded to $hsize successful."
                    $message = __('notifications.vault_expand_success', ['size' => $hsize]);
                }
            } elseif ($event instanceof ShrinkVault) {
                if (! $vtools->shrinkVault($size, $payload)) {
                    // Original: "vault shrinked to $hsize failed"
                    $message = __('notifications.vault_shrink_failed', ['size' => $hsize]);
                } else {
                    $type = 'success';
                    // Original: "Vault shrinked to $hsize successful."
                    $message = __('notifications.vault_shrink_success', ['size' => $hsize]);
                }
            } elseif ($event instanceof AdjustVault) {
                // this happens when there is a plan upgrade...
                if (! $vtools->adjustVault($newrole_id, $oldrole_id, $payload)) {
                    // Original: 'Vault adjustment failed.'
                    $message = __('notifications.vault_adjust_failed');
                } else {
                    $type = 'success';
                    // Original: 'Vault adjustment was successful.'
                    $message = __('notifications.vault_adjust_success');
                }
            }
            file_exists($lock) && unlink($lock);
        }

        if (file_exists($lock)) {
            clearstatcache();
            if (date('U') - stat($lock)[9] > 200) {
                file_exists($lock) && unlink($lock);
                $type = 'warning';
                // Original: 'Please try again.'
                $message = __('notifications.vault_busy_stale');
            } else {
                $type = 'error';
                // Original: 'Your vault is currently busy. Please try again in a couple of minutes.'
                $message = __('notifications.vault_busy');
            }
        }

        // re open user vault
        if ($vtools) {
            sleep(3);
            if (! $vtools->openVault()) {
                // Original: ' Could not re-open your vault.'
                $message .= __('notifications.vault_reopen_failed');
                Log::error($message);
            } else {
                if ($type == 'success') {
                    // add a summary of the new size...
                    if ($oldSize > 0) {
                        $newSize = $vtools->currentSize();
                        $holdSize = Number::filesize($oldSize * $units, 2);
                        $hnewSize = Number::filesize($newSize * $units, 2);
                        // Original: " Vault size changed from {$holdSize} to {$hnewSize}."
                        $message .= __('notifications.vault_size_changed', ['old' => $holdSize, 'new' => $hnewSize]);
                    }

                    if ($event instanceof AdjustVault) {
                        $tokens = UserToken::where(['user_id' => $user->id])->first();
                        $newitokens = $tokens->input_tokens_available;
                        // Original: ' Token balance adjusted from X to Y'
                        $message .= __('notifications.vault_tokens_adjusted', [
                            'old' => Number::abbreviate($olditokens),
                            'new' => Number::abbreviate($newitokens),
                        ]);
                    }
                }
            }
        }
        notifyUser($user, $message, $type);

        if ($type == 'error') {
            Log::error($message);
        } else {
            Log::info($message);
        }
        $end = microtime(true);
        Log::info(sprintf('resizeVault took %d s', $end - $ini));

    }

    public function failed(ExpandVault|ShrinkVault|AdjustVault $event, \Throwable $exception): void
    {
        $user = $event->data['user'] ?? null;
        // Original: 'Vault resize operation failed unexpectedly. Please contact support.'
        $message = __('notifications.vault_resize_unexpected');
        Log::error("ResizeVault job failed for user {$user?->id}: {$exception->getMessage()}");
        if ($user) {
            notifyUser($user, $message, 'error');
        }
    }
}
