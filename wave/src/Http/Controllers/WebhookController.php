<?php

namespace Wave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\UserToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Spatie\Permission\Models\Role;
use Wave\Http\Middleware\VerifyWebhook;
use Wave\PaddleSubscription;
use Wave\Plan;

class WebhookController extends Controller
{
    public function __construct()
    {
        if (setting('billing.paddle.public_key')) {
            $this->middleware(VerifyWebhook::class);
        }
    }

    public function __invoke(Request $request)
    {
        $alertName = $request->get('event_type', null);
        $method = null;

        switch ($alertName) {
            case 'subscription_cancelled':
            case 'subscription.canceled':
            case 'subscription_payment_failed':
                $method = 'subscriptionCancelled';
                break;
            case 'transaction.completed':
            case 'transaction_completed':
            case 'transaction_payment_failed':
                $method = 'transactionCompleted';
                break;
            default:
                $method = null;
                break;
        }

        if ($method && method_exists($this, $method)) {
            try {
                $this->{$method}($request);
            } catch (\Exception $e) {
                $message = 'Webhook handling error: '.$e->getMessage();
                Log::error($message);
                $payload = (object) [
                    'message' => $message,
                    'id' => '',
                    'via' => 'webHook',
                ];
                addEvent($payload, 'CANCELATION', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

                return response('Webhook handling failed', 500);
            }
        }

        return response('Webhook handled', 200);
    }

    protected function subscriptionCancelled(Request $request)
    {
        $subscriptionId = $request->input('data.id'); // Adjusted to match the payload structure

        // Ensure the subscription ID is provided
        if (is_null($subscriptionId)) {
            $message = 'Subscription ID missing in subscriptionCancelled webhook.';
            Log::warning($message);
            $payload = (object) [
                'message' => $message,
                'id' => '',
                'via' => 'webHook',
            ];
            addEvent($payload, 'CANCELATION', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

        $subscription = PaddleSubscription::where('subscription_id', $subscriptionId)->first();

        if (! $subscription) {
            $message = "Subscription not found: {$subscriptionId}.";
            Log::warning($message);
            $payload = (object) [
                'message' => $message,
                'id' => $subscriptionId,
                'via' => 'webHook',
            ];
            addEvent($payload, 'CANCELATION', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

        // Use the occurred_at field from the payload for the cancellation time
        $occurredAt = Carbon::parse($request->input('occurred_at', now()));

        $subscription->cancelled_at = $occurredAt;
        $subscription->status = 'cancelled';
        $subscription->save();

        $user = config('wave.user_model')::find($subscription->user_id);

        // Check if user exists
        if (! $user) {
            $message = "User not found: {$subscription->user_id}";
            Log::warning($message);
            $payload = (object) [
                'message' => $message,
                'id' => $subscriptionId,
                'via' => 'webHook',
            ];
            addEvent($payload, 'CANCELATION', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

        if ($user->locale && array_key_exists($user->locale, config('app.supported_locales', []))) {
            App::setLocale($user->locale);
        }

        // Disk expansion subscriptions must not trigger a role downgrade
        $isDiskExpansion = Plan::where('product_id', $subscription->plan_id)->where('type', 'disk')->exists();

        if ($isDiskExpansion) {
            $message = "Disk expansion subscription cancelled for: {$user->name}";
            Log::info($message);
            $payload = (object) [
                'message' => $message,
                'id' => $subscriptionId,
                'via' => 'webHook',
            ];
            addEvent($payload, 'CANCELATION', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);
            notifyUser($user, __('notifications.disk_expansion_cancelled'), 'warning');

            return;
        }

        $cancelledRole = Role::where('name', '=', 'cancelled')->first();

        // Ensure the cancelled role exists
        if (! $cancelledRole) {
            Log::error('Cancelled role not found.');

            return;
        }

        $user->role_id = $cancelledRole->id;
        $user->save();

        $message = "User subscription cancelled: {$user->name}";
        Log::warning($message);
        $payload = (object) [
            'message' => $message,
            'id' => $subscriptionId,
            'via' => 'webHook',
        ];
        addEvent($payload, 'CANCELATION', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);
        notifyUser($user, __('notifications.subscription_cancelled'), 'warning');

    }

    protected function transactionCompleted(Request $request)
    {
        $subscriptionId = $request->input('data.id'); // Adjusted to match the payload structure

        // Ensure the subscription ID is provided
        if (is_null($subscriptionId)) {
            $message = 'Subscription ID missing in subscriptionCancelled webhook.';
            Log::warning($message);
            $payload = (object) [
                'message' => $message,
                'id' => '',
                'via' => 'webHook',
            ];
            addEvent($payload, 'PAYMENT', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

        $subscription = PaddleSubscription::where('subscription_id', $subscriptionId)->first();

        if (! $subscription) {
            $message = "Subscription not found: {$subscriptionId}.";
            Log::warning($message);
            $payload = (object) [
                'message' => $message,
                'id' => $subscriptionId,
                'via' => 'webHook',
            ];
            addEvent($payload, 'PAYMENT', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

        // Use the occurred_at field from the payload for the payment time
        $last = Carbon::parse($request->input('occurred_at', now()));
        $next = Carbon::parse($request->input('data.billing_period.ends_at', now()->addDays(30)->toDateTimeString()));

        $subscription->last_payment_at = $last;
        $subscription->next_payment_at = $next;
        $subscription->save();

        $user = config('wave.user_model')::find($subscription->user_id);

        // Check if user exists
        if (! $user) {
            $message = "User not found: {$subscription->user_id}";
            Log::warning($message);
            $payload = (object) [
                'message' => $message,
                'id' => $subscriptionId,
                'via' => 'webHook',
            ];
            addEvent($payload, 'PAYMENT', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

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

        if ($user->locale && array_key_exists($user->locale, config('app.supported_locales', []))) {
            App::setLocale($user->locale);
        }

        $message = "Payment received for: {$user->name}. New token balance: ".Number::abbreviate($itokens);
        Log::info($message);
        $payload = (object) [
            'message' => $message,
            'id' => $subscriptionId,
            'via' => 'webHook',
        ];
        addEvent($payload, 'PAYMENT', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);
        notifyUser($user, __('notifications.subscription_payment_received', ['tokens' => Number::abbreviate($itokens)]), 'success');

    }
}
