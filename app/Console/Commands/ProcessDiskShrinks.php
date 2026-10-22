<?php

namespace App\Console\Commands;

use App\Events\ShrinkVault;
use App\Models\User;
use App\Providers\VaultTools;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Wave\PaddleSubscription;
use Wave\Plan;

class ProcessDiskShrinks extends Command
{
    protected $signature = 'subscriptions:process-disk-shrinks';

    protected $description = 'Find disk-shrink subscriptions due for execution and dispatch ShrinkVault events';

    public function handle(): int
    {
        // look for active plans marked for deletion
        $diskProductIds = [];
        $diskSubscriptions = [];
        $now = Carbon::now();

        $diskPlans = Plan::where('type', 'disk')->get();
        if (count($diskPlans) > 0) {
            foreach ($diskPlans as $plan) {
                $diskProductIds[] = $plan->product_id;
            }
        }

        if (count($diskProductIds) > 0) {
            $diskSubscriptions = PaddleSubscription::where('status', 'active')
                ->whereNotNull('delete_at')
                ->where(function ($q) use ($diskProductIds) {
                    // disk expansion cancellations OR plan-downgrade scheduled shrinks
                    $q->whereIn('plan_id', $diskProductIds)
                        ->orWhereNotNull('shrink_mb');
                })
                ->get();
        } else {
            // No disk plans exist yet; still process any plan-downgrade shrinks.
            $diskSubscriptions = PaddleSubscription::where('status', 'active')
                ->whereNotNull('delete_at')
                ->whereNotNull('shrink_mb')
                ->get();
        }

        if (count($diskSubscriptions) > 0) {
            foreach ($diskSubscriptions as $diskSubscription) {
                // calculate how many days left fo the current disk expansion to be due
                $deleteAt = Carbon::parse($diskSubscription->delete_at);
                $timeLeft = abs($deleteAt->diffInMinutes($now));

                if (($now > $deleteAt) && $timeLeft > 0) {
                    // this one is ready for shrinking...

                    $user = User::where('id', $diskSubscription->user_id)->first();

                    if ($user->locale && array_key_exists($user->locale, config('app.supported_locales', []))) {
                        App::setLocale($user->locale);
                    }

                    // free space in vault must be at leas 1.5 times larger than the size in the shrink (plan)
                    $percentage = 1.5;

                    // Resolve shrink size: plan-downgrade records carry shrink_mb directly;
                    // disk expansion records encode size in plan->features.
                    $exp = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
                    if ($diskSubscription->shrink_mb !== null) {
                        // Plan-downgrade scheduled shrink: size stored directly on the record.
                        $plan = Plan::where('role_id', $user->role_id)->where('type', 'service')->first()
                            ?? Plan::where('type', 'service')->first();
                        $size = $diskSubscription->shrink_mb * pow(1024, 2);
                    } else {
                        $plan = Plan::where('product_id', $diskSubscription->plan_id)->first();
                        // get a useful size from the plan description...
                        // features: 500 GB vault increase
                        $features = explode(' ', $plan->features);
                        $index = array_search(strtoupper($features[1]), $exp);
                        $units = pow(1024, $index);
                        $size = floatval($features[0] * $units);
                    }
                    $required = ($size * $percentage);

                    $free = 0;
                    $vtools = new VaultTools($user);
                    if ($vtools) {
                        if (! $vtools->isOpen()) {
                            // open user vault
                            if (! $vtools->openVault()) {
                                Log::error("could not open {$user->username}'s vault");
                                break;
                            }
                        }

                        if ($vtools->isOpen()) {
                            // FIELDS: Filesystem, Size, Used, Avail, Use%, IUse%, Inodes
                            $usage = $vtools->vaultUsage();
                            if (isset($usage['Avail'])) {
                                $exp2 = ['B', 'K', 'M', 'G', 'T', 'P'];
                                $units2 = substr($usage['Avail'], -1);
                                $index2 = array_search(strtoupper($units2), $exp2);
                                $units2 = pow(1024, $index2);
                                $free = floatval($usage['Avail']) * $units2;

                                if (Config::get('app.env') == 'local') {
                                    // TEST MODE
                                    $free = $free * 1024;
                                }
                            }
                        }
                    }

                    $cid = 0;
                    $vid = $vtools->getVaultId();
                    $uid = $user->id;
                    $gid = $user->id;

                    $hsize = Number::fileSize($size, 2);
                    $hrequired = Number::fileSize($required, 2);
                    $hfree = Number::fileSize($free, 2);

                    if ($free <= $required) {
                        // the available free space does not allow shrinking... do nothing will retry in an hour
                        // Original: "Scheduled vault shrink of {$hsize} could not run: free space ({$hfree})
                        //            is below the required minimum ({$hrequired}). Please free up space and
                        //            the shrink will be retried automatically."
                        $message = __('notifications.scheduler_shrink_no_space', [
                            'size' => $hsize,
                            'free' => $hfree,
                            'required' => $hrequired,
                        ]);
                        Log::error($message." User {$user->id}.");

                        $payload = (object) [
                            'message' => $message,
                            'plan_id' => $plan->id,
                            'description' => $plan->description,
                            'subscription' => $diskSubscription->subscription_id,
                            'subscription_id' => $diskSubscription->id,
                        ];
                        addEvent($payload, 'SCHEDULER', 'FAILED', 'ACTIVITY', $cid, $vid, $uid, $gid);
                        notifyUser($user, $message, 'warning');

                        break;
                    }

                    // Paddle is already aware that this subscription shall be canceled today
                    // so we do not need to do anything on Paddle

                    if (Config::get('app.env') == 'local') {
                        // TEST MODE
                        $size = $size / 1024;
                    }

                    // don't shrink here. dispatch an event and resturn fast
                    $data = [
                        'user' => $user,
                        'size' => $size,
                        'plan' => $plan,
                    ];
                    ShrinkVault::dispatch($data);

                    // mark the subscription as cancelled

                    // conflict here with WebhookController.php...
                    // $diskSubscription->cancelled_at = $now;
                    // $diskSubscription->status = 'cancelled';

                    $diskSubscription->delete_at = null;
                    $diskSubscription->save();

                    // Delete fake (admin test) or plan-downgrade records after execution.
                    if (str_starts_with($diskSubscription->subscription_id, 'fake_') ||
                        str_starts_with($diskSubscription->subscription_id, 'plan_switch_')) {
                        $diskSubscription->delete();
                    }

                    $message = "Shrinking SUCCESS. Vault free space is {$hfree} is enough. ";
                    $message .= "A minimum of {$hrequired} was needed. ";
                    $message .= "Shrinking user's ({$user->id}) Vault in {$hsize} ";
                    Log::info($message);

                    $payload = (object) [
                        'message' => $message,
                        'plan_id' => $plan->id,
                        'description' => $plan->description,
                        'subscription' => $diskSubscription->subscription_id,
                        'subscription_id' => $diskSubscription->id,
                    ];
                    addEvent($payload, 'SCHEDULER', 'SUCCESS', 'ACTIVITY', $cid, $vid, $uid, $gid);

                }
            }
        }

        return self::SUCCESS;
    }
}
