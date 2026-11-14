<?php

namespace Wave\Http\Livewire\Billing;

use App\Events\AdjustVault;
use App\Models\UserToken;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;
use Stripe\StripeClient;
use Wave\Actions\Billing\Paddle\AddSubscriptionIdFromTransaction;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\Subscription;

class Checkout extends Component
{
    public $billing_cycle_available = 'month'; // month, year, or both;

    public $billing_cycle_selected = 'month';

    public $billing_provider;

    public $paddle_url;

    public bool $headless = false;

    public $change = false;

    public $userSubscription = null;

    public $userPlan = null;

    public function mount()
    {
        $this->billing_provider = config('wave.billing_provider', 'stripe');
        $this->paddle_url = (config('wave.paddle.env') == 'sandbox') ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
        $this->updateCycleBasedOnPlans();

        if ($this->change) {
            // if we are changing the user plan as opposecd to checking out the first time.
            $this->userSubscription = auth()->user()->subscription;
            $this->userPlan = auth()->user()->subscription->plan;
        }
    }

    public function redirectToStripeCheckout(Plan $plan)
    {
        $stripe = new StripeClient(config('wave.stripe.secret_key'));

        $price_id = $this->billing_cycle_selected == 'month' ? $plan->monthly_price_id : $plan->yearly_price_id ?? null;

        $checkout_session = $stripe->checkout->sessions->create([
            'line_items' => [[
                'price' => $price_id,
                'quantity' => 1,
            ]],
            'metadata' => [
                'billable_type' => 'user',
                'billable_id' => auth()->user()->id,
                'plan_id' => $plan->id,
                'billing_cycle' => $this->billing_cycle_selected,
            ],
            'mode' => 'subscription',
            'success_url' => url('subscription/welcome'),
            'cancel_url' => url('settings/subscription'),
        ]);

        return redirect($checkout_session->url);
    }

    public function updateCycleBasedOnPlans()
    {
        $plans = Plan::where('active', 1)->get();
        $hasMonthly = false;
        $hasYearly = false;
        foreach ($plans as $plan) {
            if (! empty($plan->monthly_price_id)) {
                $hasMonthly = true;
            }
            if (! empty($plan->yearly_price_id)) {
                $hasYearly = true;
            }
        }
        if ($hasMonthly && $hasYearly) {
            $this->billing_cycle_available = 'both';
        } elseif ($hasMonthly) {
            $this->billing_cycle_available = 'month';
        } elseif ($hasYearly) {
            $this->billing_cycle_available = 'year';
            $this->billing_cycle_selected = 'year';
        }
    }

    #[On('savePaddleSubscription')]
    public function savePaddleSubscription($transactionId)
    {
        $subscription = app(AddSubscriptionIdFromTransaction::class)($transactionId);
        if (! is_null($subscription)) {
            return redirect('/subscription/welcome');
        }

        $this->js('closeLoader()');
        Notification::make()
            ->title('Unable to obtain subscription information from payment provider.')
            ->danger()
            ->send();
    }

    #[On('verifyPaddleTransaction')]
    public function verifyPaddleTransaction($transactionId)
    {

        $transaction = null;

        $response = Http::withToken(config('wave.paddle.api_key'))->get($this->paddle_url.'/transactions/'.$transactionId);

        if ($response->successful()) {
            $resBody = json_decode($response->body());
            if (isset($resBody->data->status) && ($resBody->data->status == 'paid' || $resBody->data->status == 'completed' || $resBody->data->status == 'ready')) {
                $transaction = $resBody->data;
            }
        }

        if ($transaction) {
            // Proceed with processing the transaction

            $user = auth()->user();

            if ($this->billing_cycle_selected == 'month') {
                $plan = Plan::where('monthly_price_id', $transaction->items[0]->price->id)->first();
            } else {
                $plan = Plan::where('yearly_price_id', $transaction->items[0]->price->id)->first();
            }

            if (! isset($plan->id)) {
                $this->js('Paddle.Checkout.close()');
                Notification::make()
                    ->title('Plan Price ID not found. Something went wrong during the checkout process')
                    ->success()
                    ->send();

                return;
            }

            auth()->user()->syncRoles([]);
            auth()->user()->assignRole($plan->role->name);

            Subscription::create([
                'billable_type' => 'user',
                'billable_id' => auth()->user()->id,
                'plan_id' => $plan->id,
                'vendor_slug' => 'paddle',
                'vendor_transaction_id' => $transactionId,
                'vendor_customer_id' => $transaction->customer_id,
                'vendor_subscription_id' => $transaction->subscription_id,
                'cycle' => $this->billing_cycle_selected,
                'status' => 'active',
                'seats' => 1,
            ]);

            $this->js('savePaddleSubscription("'.$transactionId.'")');

        } else {
            $this->js('Paddle.Checkout.close()');
            Notification::make()
                ->title('Error processing the transaction. Please try again.')
                ->danger()
                ->send();
        }

        // if we got here something went wrong and we need to let the user know.

    }

    #[On('switchPlanById')]
    public function switchPlanById(int $planId, string $cycle = 'month'): void
    {
        $this->billing_cycle_selected = in_array($cycle, ['month', 'year']) ? $cycle : 'month';
        $plan = Plan::findOrFail($planId);
        $this->switchPlan($plan);
    }

    public function switchPlan(Plan $plan): mixed
    {
        $subscription = auth()->user()->subscription;
        $user = auth()->user();
        $oldRoleId = $user->role_id;
        $newRoleId = $plan->role_id;

        // Determine old vs new disk sizes (MB) to classify as upgrade or downgrade.
        $oldPlan = $subscription->plan;
        $oldDiskMb = $oldPlan ? $this->planDiskSizeMB($oldPlan) : 0;
        $newDiskMb = $this->planDiskSizeMB($plan);
        $isDowngrade = $newDiskMb < $oldDiskMb;

        // Block downgrade if the user has active disk expansion subscriptions.
        if ($isDowngrade) {
            $diskPlanProductIds = Plan::where('type', 'disk')->pluck('product_id')->toArray();
            $hasDiskExpansions = ! empty($diskPlanProductIds) && PaddleSubscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->whereIn('plan_id', $diskPlanProductIds)
                ->exists();

            if ($hasDiskExpansions) {
                Notification::make()
                    ->title(__('notifications.plan_downgrade_blocked'))
                    ->danger()
                    ->send();

                addEvent(
                    (object) ['message' => 'plan downgrade blocked by active disk expansions', 'plan' => $plan->name],
                    'SWITCHPLAN', 'FAILED', 'ACTIVITY', 0, 0, $user->id, $user->id
                );

                return null;
            }
        }

        $price_id = ($this->billing_cycle_selected == 'month') ? $plan->monthly_price_id : $plan->yearly_price_id ?? null;

        $response = Http::withToken(config('wave.paddle.api_key'))->patch(
            $this->paddle_url.'/subscriptions/'.$subscription->vendor_subscription_id,
            [
                'items' => [['price_id' => $price_id, 'quantity' => 1]],
                'proration_billing_mode' => 'prorated_immediately',
            ]
        );

        if (! $response->successful()) {
            addEvent(
                (object) ['message' => 'plan switch Paddle API error', 'plan' => $plan->name],
                'SWITCHPLAN', 'FAILED', 'ACTIVITY', 0, 0, $user->id, $user->id
            );
            notifyUser($user, __('notifications.plan_switch_error'), 'error');

            return null;
        }

        // Persist subscription and role changes locally.
        $subscription->plan_id = $plan->id;
        $subscription->cycle = $this->billing_cycle_selected;
        $subscription->save();

        $user->forceFill(['role_id' => $newRoleId])->save();
        $user->switchPlans($plan);

        addEvent(
            (object) [
                'message' => 'plan switched',
                'plan' => $plan->name,
                'old_role_id' => $oldRoleId,
                'new_role_id' => $newRoleId,
                'is_downgrade' => $isDowngrade,
            ],
            'SWITCHPLAN', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id
        );

        if ($isDowngrade) {
            // Adjust tokens immediately (reduce to new plan level).
            if ($oldPlan) {
                $this->adjustTokensForPlanChange($user, $oldPlan, $plan);
            }

            // Schedule vault shrink at the next billing period.
            $responseBody = $response->json();
            $nextBillAt = isset($responseBody['data']['next_billed_at'])
                ? Carbon::parse($responseBody['data']['next_billed_at'])
                : Carbon::now()->addDays(30);

            $shrinkMb = $oldDiskMb - $newDiskMb;

            PaddleSubscription::create([
                'subscription_id' => 'plan_switch_'.uniqid(),
                'plan_id' => 'plan_downgrade',
                'user_id' => $user->id,
                'status' => 'active',
                'cancel_url' => 'n/a',
                'update_url' => 'n/a',
                'last_payment_at' => Carbon::now(),
                'next_payment_at' => $nextBillAt,
                'delete_at' => $nextBillAt,
                'shrink_mb' => $shrinkMb,
            ]);

            notifyUser(
                $user,
                __('notifications.plan_downgraded_scheduled', [
                    'plan' => $plan->name,
                    'date' => $nextBillAt->toDateString(),
                ]),
                'warning'
            );
        } else {
            // Upgrade or same-disk plan change: dispatch AdjustVault to handle vault
            // expansion and token adjustment asynchronously.
            $sizeDiffBytes = ($newDiskMb - $oldDiskMb) * pow(1024, 2);

            AdjustVault::dispatch([
                'user' => $user,
                'size' => $sizeDiffBytes,
                'plan' => $plan,
                'newrole_id' => $newRoleId,
                'oldrole_id' => $oldRoleId,
            ]);

            notifyUser($user, __('notifications.plan_upgraded', ['plan' => $plan->name]), 'success');
        }

        return redirect('/settings/subscription')->with(['update' => true]);
    }

    /**
     * Adjust the user's token balance for a plan change (downgrade path).
     * Mirrors the token logic in VaultTools::adjustVault() without touching the vault.
     */
    private function adjustTokensForPlanChange($user, Plan $oldPlan, Plan $newPlan): void
    {
        $oldTokens = explode(' ', $oldPlan->getTokenAmount());
        $newTokens = explode(' ', $newPlan->getTokenAmount());
        $qty = floatval($newTokens[0]) - floatval($oldTokens[0]);

        if ($qty == 0) {
            return;
        }

        $tokens = UserToken::firstOrNew(['user_id' => $user->id]);
        $tokens->save();

        $tokens->update([
            'input_tokens_available' => max(0, $tokens->input_tokens_available + intval($qty * pow(10, 6))),
            'output_tokens_available' => max(0, $tokens->output_tokens_available + intval($qty * pow(10, 3))),
            'total_tokens_available' => max(0, $tokens->total_tokens_available + intval($qty * (pow(10, 6) + pow(10, 3)))),
        ]);
    }

    /**
     * Parse a plan's disk size and return it in MB.
     */
    private function planDiskSizeMB(Plan $plan): int
    {
        try {
            $parts = explode(' ', $plan->getDiskSize());
            $exp = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
            $index = array_search(strtoupper($parts[1] ?? 'GB'), $exp);
            $bytes = floatval($parts[0] ?? 0) * pow(1024, $index !== false ? $index : 3);

            return intval($bytes / pow(1024, 2));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function render()
    {
        return view('wave::livewire.billing.checkout', [
            'plans' => Plan::where('active', 1)->get(),
        ]);
    }
}
