<?php

namespace Wave\Http\Controllers;

use App\Events\ExpandVault;
use App\Events\ShrinkVault;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\UserToken;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Spatie\Permission\Models\Role;
use Wave\Http\Controllers\Auth\RegisterController;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\Subscription;
use Wave\User;

class SubscriptionController extends Controller
{
    private $paddle_url;

    private $vendor_id;

    private $api_key;

    public function __construct()
    {
        $this->api_key = config('wave.paddle.api_key');
        $this->vendor_id = config('wave.paddle.vendor');

        $this->paddle_url = (config('wave.paddle.env') == 'sandbox') ? 'https://sandbox-api.paddle.com' : 'https://api.paddle.com';
    }

    public function cancel(Request $request)
    {
        $this->cancelSubscription($request->id);

        return response()->json(['status' => 1]);
    }

    private function cancelSubscription()
    {
        // Ensure user is authenticated
        if (! auth()->check()) {
            return redirect('/login')->with(['message' => 'Please log in to continue.', 'message_type' => 'danger']);
        }

        // Auth user get latest subscription id
        $subscription_id = auth()->user()->latestSubscription->subscription_id;

        // Ensure the provided subscription ID matches the user's subscription ID
        $localSubscription = Subscription::where('subscription_id', $subscription_id)->first();

        if (! $localSubscription || auth()->user()->latestSubscription->subscription_id != $subscription_id) {
            return back()->with(['message' => 'Invalid subscription ID.', 'message_type' => 'danger']);
        }

        try {
            $response = Http::withToken($this->api_key)
                ->post($this->paddle_url.'/subscriptions/'.$subscription_id.'/cancel', [
                    'effective_from' => 'immediately',
                ]);

            Log::info($response->body());

            // Check if the request was successful
            if ($response->successful()) {
                $body = $response->json();

                if (isset($body['data']) && isset($body['data']['status']) && $body['data']['status'] == 'canceled') {

                    // Update subscription in local database
                    $localSubscription->cancelled_at = Carbon::parse($body['data']['canceled_at']);
                    $localSubscription->status = 'cancelled';
                    $localSubscription->save();

                    // Update user's role to "cancelled"
                    $user = User::find($localSubscription->user_id);
                    $cancelledRole = Role::where('name', '=', 'cancelled')->first();
                    $user->role_id = $cancelledRole->id;
                    $user->save();

                    $message = 'Your subscription has been successfully canceled.';
                    Log::info($message);
                    $payload = (object) [
                        'message' => $message,
                        'subscription_id' => $subscription_id,
                        'via' => 'web',
                    ];
                    addEvent($payload, 'CANCELATION', 'SUCCESS', 'ACTIVITY', $this->cid, $this->vid, $this->uid, $this->gid);

                    return back()->with(['message' => $message, 'message_type' => 'success']);
                } else {
                    // Handle any errors that were returned in the response body
                    $error = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error while canceling the subscription.';
                    Log::error($error);
                    $payload = (object) [
                        'message' => $error,
                        'subscription_id' => $subscription_id,
                        'via' => 'web',
                    ];
                    addEvent($payload, 'CANCELATION', 'FAILED', 'ACTIVITY', $this->cid, $this->vid, $this->uid, $this->gid);

                    return back()->with(['message' => $error, 'message_type' => 'danger']);
                }
            } else {
                // Handle failed HTTP requests
                return back()->with(['message' => 'Failed to cancel the subscription. Please try again later.', 'message_type' => 'danger']);
            }
        } catch (ConnectionException $e) {
            $message = $e->getMessage();
            Log::error($message);
            $payload = (object) [
                'message' => $message,
                'subscription_id' => $subscription_id,
                'via' => 'web',
            ];
            addEvent($payload, 'CANCELATION', 'FAILED', 'ACTIVITY', $this->cid, $this->vid, $this->uid, $this->gid);
        } catch (RequestException $e) {
            $message = $e->getMessage();
            Log::error($message);
            $payload = (object) [
                'message' => $message,
                'subscription_id' => $subscription_id,
                'via' => 'web',
            ];
            addEvent($payload, 'CANCELATION', 'FAILED', 'ACTIVITY', $this->cid, $this->vid, $this->uid, $this->gid);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            Log::error($message);
            $payload = (object) [
                'message' => $message,
                'subscription_id' => $subscription_id,
                'via' => 'web',
            ];
            addEvent($payload, 'CANCELATION', 'FAILED', 'ACTIVITY', $this->cid, $this->vid, $this->uid, $this->gid);
        }
    }

    public function checkout(Request $request)
    {
        $retryCount = 5;
        $initialDelay = 2;
        $transaction = null;
        $status = 0;
        $message = '';
        $guest = (auth()->guest()) ? 1 : 0;

        for ($i = 0; $i < $retryCount; $i++) {
            $response = Http::withToken($this->api_key)->get($this->paddle_url.'/transactions/'.$request->checkout_id);

            Log::info($response->body());
            if ($response->successful()) {
                $resBody = json_decode($response->body());
                if (isset($resBody->data->status) && ! is_null($resBody->data->subscription_id)) {
                    $transaction = $resBody->data;
                    break;
                }
            }

            sleep($initialDelay * (2 ** $i));
        }

        if ($transaction) {
            // Proceed with processing the transaction
            $plans = Plan::all();
            if ($transaction->origin === 'web' && $plans->contains('monthly_price_id', $transaction->items[0]->price->id)) {
                $subscriptionUser = Http::withToken($this->api_key)->get($this->paddle_url.'/subscriptions/'.$transaction->subscription_id);
                $subscriptionData = json_decode($subscriptionUser->body());
                $subscription = $subscriptionData->data;

                $customerResponse = Http::withToken($this->api_key)->get($this->paddle_url.'/customers/'.$subscription->customer_id);
                $customerData = json_decode($customerResponse->body());
                $customerEmail = $customerData->data->email;
                $customerName = $customerData->data->name;
                if (empty($customerName)) {
                    $nameParts = explode('@', $customerEmail);
                    $customerName = $nameParts[0];
                }

                if ($guest) {
                    if (User::where('email', $customerEmail)->exists()) {
                        $user = User::where('email', $customerEmail)->first();
                    } else {
                        $registration = new RegisterController;
                        $user_data = [
                            'name' => $customerName,
                            'email' => $customerEmail,
                            'password' => Hash::make(uniqid()),
                        ];
                        $user = $registration->create($user_data);
                        Auth::login($user);
                    }
                } else {
                    $user = auth()->user();
                }

                $plan = Plan::where('monthly_price_id', $transaction->items[0]->price->id)->first();

                // Update user role based on plan
                $user->role_id = $plan->role_id;
                $user->save();

                // Create or update subscription details
                $subscriptionRecord = Subscription::create([
                    'subscription_id' => $transaction->subscription_id,
                    'plan_id' => $transaction->items[0]->price->product_id,
                    'user_id' => $user->id,
                    'status' => $subscription->status,
                    'last_payment_at' => $subscription->first_billed_at,
                    'next_payment_at' => $subscription->next_billed_at,
                    'cancel_url' => $subscription->management_urls->cancel,
                    'update_url' => $subscription->management_urls->update_payment_method,
                ]);

                $status = 1;
            } else {
                // Original: 'Error locating that subscription product id. Please contact us if you think this is incorrect.'
                $message = __('notifications.subscription_product_error');
                $checkoutUser = auth()->guest() ? null : auth()->user();
                $payload = (object) ['message' => $message, 'checkout_id' => $request->checkout_id, 'via' => 'web'];
                addEvent($payload, 'CHECKOUT', 'FAILED', 'ACTIVITY', 0, 0, $checkoutUser?->id ?? 0, $checkoutUser?->id ?? 0);
                if ($checkoutUser) {
                    notifyUser($checkoutUser, $message, 'error');
                }
            }
        } else {
            // Original: 'Error processing the transaction. Please try again.'
            $message = __('notifications.transaction_error');
            $checkoutUser = auth()->guest() ? null : auth()->user();
            $payload = (object) ['message' => $message, 'checkout_id' => $request->checkout_id, 'via' => 'web'];
            addEvent($payload, 'CHECKOUT', 'FAILED', 'ACTIVITY', 0, 0, $checkoutUser?->id ?? 0, $checkoutUser?->id ?? 0);
            if ($checkoutUser) {
                notifyUser($checkoutUser, $message, 'error');
            }
        }

        return response()->json([
            'status' => $status,
            'message' => $message,
            'guest' => $guest,
        ]);
    }

    public function transactions(User $user)
    {

        // Check if user has a subscription
        if (! $user->latestSubscription) {
            return [];
        }

        $invoices = [];
        $response = Http::withToken($this->api_key)->get($this->paddle_url.'/transactions', [
            'subscription_id' => $user->latestSubscription->subscription_id,
        ]);

        $transactions = json_decode($response->body());

        addEvent((object) ['message' => 'transactions list requested', 'subscription_id' => $user->latestSubscription->subscription_id], 'TRANSACTIONS', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);

        return $transactions->data;

    }

    public function invoice(Request $request, $transactionId)
    {

        $response = Http::withToken($this->api_key)->get($this->paddle_url.'/transactions/'.$transactionId.'/invoice');
        $invoice = json_decode($response->body());

        $uid = auth()->id() ?? 0;
        addEvent((object) ['message' => 'invoice downloaded', 'transaction_id' => $transactionId], 'INVOICE', 'SUCCESS', 'ACTIVITY', 0, 0, $uid, $uid);

        // redirect user to the invoice download URL
        return redirect($invoice->data->url);
    }

    public function expandDisk(Request $request): JsonResponse
    {
        $guest = auth()->guest() ? 1 : 0;
        $plan = Plan::where('id', $request->item)->first();

        if (! $plan || $plan->type !== 'disk') {
            // Original: 'Wrong plan type for disk expansion.'
            $message = __('notifications.wrong_plan_disk_expand');
            Log::error($message);
            $user = $request->user() ?? auth()->user();
            $payload = (object) ['message' => $message, 'item' => $request->item, 'via' => 'web'];
            addEvent($payload, 'VAULT_EXPAND', 'FAILED', 'ACTIVITY', 0, 0, $user->id, $user->id);
            notifyUser($user, $message, 'error');

            return response()->json(['status' => 0, 'message' => $message, 'guest' => $guest]);
        }

        // Parse size from features field (e.g. "10 GB vault increase")
        $features = explode(' ', $plan->features);
        $exp = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = array_search(strtoupper($features[1] ?? 'GB'), $exp);
        $multiplier = pow(1024, $index !== false ? $index : 3);
        $size = floatval(($features[0] ?? 0) * $multiplier);
        $hsize = Number::fileSize($size, 2);

        if (config('app.env') === 'local') {
            $size = $size / 1024;
        }

        $user = $request->user() ?? auth()->user();

        $data = ['user' => $user, 'size' => $size, 'plan' => $plan];
        ExpandVault::dispatch($data);

        $payload = (object) [
            'message' => "Vault {$hsize} expansion requested.",
            'plan_id' => $plan->id,
            'plan' => $plan->name,
            'via' => 'web',
        ];
        addEvent($payload, 'VAULT_EXPAND', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);

        // Original: "Vault {$hsize} expansion requested. This operation can take several minutes."
        $message = __('notifications.vault_expand_requested', ['size' => $hsize]);
        notifyUser($user, $message, 'success');

        return response()->json(['status' => 1, 'message' => $message, 'guest' => $guest]);
    }

    public function cancelDisk(Request $request): JsonResponse
    {
        $guest = auth()->guest() ? 1 : 0;
        $plan = Plan::where('id', $request->item)->first();

        if (! $plan || $plan->type !== 'disk') {
            // Original: 'Wrong plan type for disk cancellation.'
            $message = __('notifications.wrong_plan_disk_cancel');
            Log::error($message);
            $user = $request->user() ?? auth()->user();
            $payload = (object) ['message' => $message, 'item' => $request->item, 'via' => 'web'];
            addEvent($payload, 'VAULT_SHRINK', 'FAILED', 'ACTIVITY', 0, 0, $user->id, $user->id);
            notifyUser($user, $message, 'error');

            return response()->json(['status' => 0, 'message' => $message, 'guest' => $guest]);
        }

        $features = explode(' ', $plan->features);
        $exp = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = array_search(strtoupper($features[1] ?? 'GB'), $exp);
        $multiplier = pow(1024, $index !== false ? $index : 3);
        $size = floatval(($features[0] ?? 0) * $multiplier);
        $hsize = Number::fileSize($size, 2);

        if (config('app.env') === 'local') {
            $size = $size / 1024;
        }

        $user = $request->user() ?? auth()->user();

        $data = ['user' => $user, 'size' => $size, 'plan' => $plan];
        ShrinkVault::dispatch($data);

        $payload = (object) [
            'message' => "Vault {$hsize} shrink requested.",
            'plan_id' => $plan->id,
            'plan' => $plan->name,
            'via' => 'web',
        ];
        addEvent($payload, 'VAULT_SHRINK', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);

        // Original: "Vault {$hsize} shrink requested. This operation can take several minutes."
        $message = __('notifications.vault_shrink_requested', ['size' => $hsize]);
        notifyUser($user, $message, 'warning');

        return response()->json(['status' => 1, 'message' => $message, 'guest' => $guest]);
    }

    public function scheduleCancelDisk(Request $request): JsonResponse
    {
        $guest = auth()->guest() ? 1 : 0;
        $user = $request->user() ?? auth()->user();
        $plan = Plan::where('id', $request->item)->first();
        $now = Carbon::now();

        if (! $plan || $plan->type !== 'disk') {
            // Original: 'Wrong plan type for scheduled disk cancellation.'
            $message = __('notifications.wrong_plan_disk_schedule');
            Log::error($message);
            $payload = (object) ['message' => $message, 'item' => $request->item, 'via' => 'web'];
            addEvent($payload, 'VAULT_SHRINK_SCHEDULED', 'FAILED', 'ACTIVITY', 0, 0, $user->id, $user->id);
            notifyUser($user, $message, 'error');

            return response()->json(['status' => 0, 'message' => $message, 'guest' => $guest]);
        }

        $features = explode(' ', $plan->features);
        $exp = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = array_search(strtoupper($features[1] ?? 'GB'), $exp);
        $hsize = ($features[0] ?? '?').' '.($features[1] ?? 'GB');

        // Admin path: create a fake subscription scheduled 1 minute out (for testing)
        if ($user->hasRole('admin')) {
            $scheduledDate = Carbon::now()->addMinutes(1);

            PaddleSubscription::create([
                'subscription_id' => 'fake_'.uniqid(),
                'plan_id' => $plan->product_id,
                'user_id' => $user->id,
                'status' => 'active',
                'cancel_url' => 'n/a',
                'update_url' => 'n/a',
                'last_payment_at' => Carbon::now()->subDays(10),
                'next_payment_at' => Carbon::now()->addDays(20),
                'delete_at' => $scheduledDate,
            ]);

            // Original: "Vault {$hsize} shrink scheduled for {$scheduledDate}."
            $message = __('notifications.vault_shrink_scheduled', ['size' => $hsize, 'date' => $scheduledDate]);
            notifyUser($user, $message, 'warning');

            $payload = (object) ['message' => $message, 'plan_id' => $plan->id, 'scheduled_at' => $scheduledDate, 'via' => 'web'];
            addEvent($payload, 'VAULT_SHRINK_SCHEDULED', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);

            return response()->json(['status' => 1, 'message' => $message, 'guest' => $guest]);
        }

        // Normal user path: find existing active disk subscription and mark for deletion
        $diskExpansion = PaddleSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('plan_id', $plan->product_id)
            ->first();

        if ($diskExpansion) {
            $next = Carbon::parse($diskExpansion->next_payment_at);
            $daysLeft = abs($next->diffInDays($now));

            if ($now < $next && $daysLeft > 0) {
                $scheduledDate = $next->format('Y-m-d H:i:s');
                $diskExpansion->update(['delete_at' => $scheduledDate]);

                $message = __('notifications.vault_shrink_scheduled', ['size' => $hsize, 'date' => $scheduledDate]);
                notifyUser($user, $message, 'warning');

                $payload = (object) ['message' => $message, 'plan_id' => $plan->id, 'scheduled_at' => $scheduledDate, 'via' => 'web'];
                addEvent($payload, 'VAULT_SHRINK_SCHEDULED', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);

                return response()->json(['status' => 1, 'message' => $message, 'guest' => $guest]);
            }
        }

        // Original: 'Vault shrink request failed. No active disk expansion found.'
        $message = __('notifications.vault_shrink_no_disk');
        Log::error($message);
        $payload = (object) ['message' => $message, 'plan_id' => $plan->id, 'via' => 'web'];
        addEvent($payload, 'VAULT_SHRINK_SCHEDULED', 'FAILED', 'ACTIVITY', 0, 0, $user->id, $user->id);
        notifyUser($user, $message, 'error');

        return response()->json(['status' => 0, 'message' => $message, 'guest' => $guest]);
    }

    public function addTokens(Request $request): JsonResponse
    {
        $guest = auth()->guest() ? 1 : 0;
        $user = $request->user() ?? auth()->user();
        $plan = Plan::where('id', $request->item)->first();

        if (! $plan || $plan->type !== 'tokens') {
            // Original: 'Wrong plan type for token purchase.'
            $message = __('notifications.wrong_plan_tokens');
            Log::error($message);

            $payload = (object) ['message' => $message, 'plan_id' => $plan?->id, 'via' => 'web'];
            addEvent($payload, 'BUY_TOKENS', 'FAILED', 'ACTIVITY', 0, 0, $user->id, $user->id);
            notifyUser($user, $message, 'error');

            return response()->json(['status' => 0, 'message' => $message, 'guest' => $guest]);
        }

        $qty = intval(str_replace('mtoken', '', $plan->slug));

        $tokens = UserToken::firstOrNew(['user_id' => $user->id]);
        $tokens->save();

        $itokens = $tokens->input_tokens_available + ($qty * pow(10, 6));
        $otokens = $tokens->output_tokens_available + ($qty * pow(10, 3));

        $tokens->update([
            'input_tokens_available' => $itokens,
            'output_tokens_available' => $otokens,
            'total_tokens_available' => $itokens + $otokens,
        ]);

        // Original: 'The token purchase was successful. Your new token balance is X tokens.'
        $message = __('notifications.tokens_purchase_success', ['tokens' => Number::forHumans($itokens)]);
        notifyUser($user, $message, 'success');

        $payload = (object) ['message' => $message, 'plan_id' => $plan->id, 'via' => 'web'];
        addEvent($payload, 'BUY_TOKENS', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);

        return response()->json(['status' => 1, 'message' => $message, 'guest' => $guest]);
    }

    public function addSeats(Request $request): JsonResponse
    {
        $guest = auth()->guest() ? 1 : 0;
        $user = $request->user() ?? auth()->user();
        $plan = Plan::where('id', $request->item)->first();
        $qty = max(1, (int) ($request->quantity ?? 1));

        if (! $plan || $plan->type !== 'seat') {
            $message = __('notifications.wrong_plan_seats');
            Log::error($message);
            $payload = (object) ['message' => $message, 'plan_id' => $plan?->id, 'via' => 'web'];
            addEvent($payload, 'SEAT_PURCHASE', 'FAILED', 'ACTIVITY', 0, 0, $user->id, $user->id);
            notifyUser($user, $message, 'error');

            return response()->json(['status' => 0, 'message' => $message, 'guest' => $guest]);
        }

        if (! $user->hasRole(['Team', 'Enterprise', 'Self-hosted'])) {
            $message = __('notifications.seat_addon_requires_team');
            notifyUser($user, $message, 'error');

            return response()->json(['status' => 0, 'message' => $message, 'guest' => $guest]);
        }

        $group = Group::where('owner_id', $user->id)->first();
        if (! $group) {
            $message = __('notifications.seat_addon_no_group');
            notifyUser($user, $message, 'error');

            return response()->json(['status' => 0, 'message' => $message, 'guest' => $guest]);
        }

        $group->increment('max_members', $qty);
        $group->refresh();

        $payload = (object) [
            'message' => "Extra seats purchased: +{$qty} seat(s) for group {$group->name}. New total: {$group->max_members}.",
            'plan_id' => $plan->id,
            'quantity' => $qty,
            'via' => 'web',
        ];
        addEvent($payload, 'SEAT_PURCHASE', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id);

        $message = __('settings.seat_purchase_success_body', ['qty' => $qty, 'total' => $group->max_members]);
        notifyUser($user, $message, 'success');

        return response()->json(['status' => 1, 'message' => $message, 'guest' => $guest]);
    }

    public function switchPlans(Request $request)
    {
        $plan = Plan::where('monthly_price_id', $request->plan_id)->first();

        if (isset($plan->id)) {
            // Update the user plan with Paddle
            $response = Http::withToken($this->api_key)->patch(
                $this->paddle_url.'/subscriptions/'.(string) $request->user()->latestSubscription->subscription_id,
                [
                    'items' => [
                        [
                            'price_id' => $plan->monthly_price_id,
                            'quantity' => 1,
                        ],
                    ],
                    'proration_billing_mode' => 'prorated_immediately',
                ]
            );

            if ($response->successful()) {
                $body = $response->json();

                if (isset($body['data']) && $body['data']['status'] == 'active') {
                    // Update the user role associated with the updated plan
                    $request->user()->forceFill([
                        'role_id' => $plan->role_id,
                    ])->save();

                    // Update the subscription with the updated plan in the local database
                    $request->user()->subscription->update([
                        'plan_id' => $request->plan_id,
                    ]);

                    $uid = $request->user()->id;
                    addEvent((object) ['message' => 'plan switched', 'plan' => $plan->name], 'SWITCHPLAN', 'SUCCESS', 'ACTIVITY', 0, 0, $uid, $uid);

                    return back()->with(['message' => 'Successfully switched to the '.$plan->name.' plan.', 'message_type' => 'success']);
                }
            }
        }

        return back()->with(['message' => 'Sorry, there was an issue updating your plan.', 'message_type' => 'danger']);
    }
}
