<?php

namespace Wave\Http\Controllers\Billing\Webhooks;

use App\Events\SendUserEmail;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\UserToken;
use App\Services\AccountSuspensionService;
use App\Services\LicenseCheckoutService;
use App\Services\LicenseRevocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\Subscription;

class PaddleWebhook extends Controller
{
    public function handler(Request $request): JsonResponse
    {
        $event = $request->get('event_type', null);

        Log::info("PaddleWebhook: received event '{$event}'");

        switch ($event) {
            // ── Subscription lifecycle ──────────────────────────────────────
            case 'subscription.canceled':
            case 'subscription_cancelled':
            case 'subscription_payment_failed':
                $this->subscriptionCancelled($request);
                break;

            case 'subscription.activated':
                $this->subscriptionActivated($request);
                break;

                // ── Adjustments (refunds / chargebacks) ─────────────────────────
            case 'adjustment.updated':
                $this->adjustmentUpdated($request);
                break;

                // ── Recurring payments ──────────────────────────────────────────
            case 'transaction.completed':
            case 'transaction_completed':
            case 'transaction_payment_failed':
                $this->transactionCompleted($request);
                break;

            default:
                break;
        }

        return response()->json(['message' => 'Webhook handled successfully'], 200);
    }

    // ─── Subscription cancelled ───────────────────────────────────────────────

    protected function subscriptionCancelled(Request $request): void
    {
        $subscriptionId = $request->input('data.id');

        if (is_null($subscriptionId)) {
            Log::warning('PaddleWebhook: subscription ID missing in cancellation payload.');

            return;
        }

        // Update the Wave Subscription record and downgrade role.
        $subscription = Subscription::where('vendor_subscription_id', $subscriptionId)
            ->where('status', 'active')
            ->first();

        if ($subscription) {
            $subscription->cancel();
        }

        // Also mark the PaddleSubscription record if present.
        $paddleSub = PaddleSubscription::where('subscription_id', $subscriptionId)->first();
        if ($paddleSub) {
            $paddleSub->cancelled_at = Carbon::parse($request->input('occurred_at', now()));
            $paddleSub->status = 'cancelled';
            $paddleSub->save();
        }

        // Resolve the user from whichever record we found.
        $user = $subscription?->user
            ?? ($paddleSub ? (config('wave.user_model')::find($paddleSub->user_id)) : null);

        if (! $user) {
            Log::warning("PaddleWebhook: no user found for subscription_id={$subscriptionId}");

            return;
        }

        // If this is a seat add-on subscription, handle seat removal instead of account suspension.
        if ($paddleSub && $this->isSeatPlan($paddleSub->plan_id)) {
            $this->handleSeatCancellation($user, $paddleSub, 'seat_cancellation');

            return;
        }

        // If this is a disk expansion subscription, cancel without role change.
        if ($paddleSub && $this->isDiskPlan($paddleSub->plan_id)) {
            $this->handleDiskCancellation($user, $paddleSub);

            return;
        }

        // Expire any self-hosted licenses tied to this subscription.
        if ($subscription) {
            app(LicenseRevocationService::class)->expireForSubscription($subscription->id);
        }

        $this->suspension()->suspend($user, 'cancellation', $request->input('data', []));
    }

    // ─── Subscription activated ───────────────────────────────────────────────

    protected function subscriptionActivated(Request $request): void
    {
        $subscription = $this->findSubscription($request->input('data.id'));

        if (! $subscription) {
            return;
        }

        $user = $subscription->user;
        if (! $user) {
            Log::warning('PaddleWebhook: no user found for subscription.activated');

            return;
        }

        if (! $user->hasRole('suspended')) {
            Log::info("PaddleWebhook: subscription.activated — {$user->email} is not suspended, skipping.");

            return;
        }

        $this->suspension()->reactivate($user, 'subscription_activated', $request->input('data', []));
    }

    // ─── Adjustments (refunds / chargebacks) ─────────────────────────────────

    protected function adjustmentUpdated(Request $request): void
    {
        $data = $request->input('data', []);
        $status = $data['status'] ?? null;
        $action = $data['action'] ?? null;
        $subscriptionId = $data['subscription_id'] ?? null;

        Log::info("PaddleWebhook: adjustment.updated — action={$action}, status={$status}");

        if (! in_array($action, ['refund', 'chargeback'], true)) {
            Log::info("PaddleWebhook: adjustment action '{$action}' is not handled, skipping.");

            return;
        }

        $subscription = $this->findSubscription($subscriptionId);
        if (! $subscription) {
            Log::warning("PaddleWebhook: no subscription found for adjustment subscription_id={$subscriptionId}");

            return;
        }

        $user = $subscription->user;
        if (! $user) {
            Log::warning('PaddleWebhook: no user found for adjustment.updated');

            return;
        }

        if ($status === 'approved') {
            $reason = $action === 'chargeback' ? 'chargeback' : 'refund';

            // If it's a seat add-on, remove seats instead of suspending the account owner.
            $paddleSub = PaddleSubscription::where('subscription_id', $subscriptionId)->first();
            if ($paddleSub && $this->isSeatPlan($paddleSub->plan_id)) {
                $this->handleSeatCancellation($user, $paddleSub, $reason);

                return;
            }

            // Revoke any self-hosted licenses tied to this subscription immediately.
            app(LicenseRevocationService::class)->revokeForSubscription($subscription->id, $reason);

            $this->suspension()->suspend($user, $reason, $data);

            return;
        }

        if ($status === 'rejected' && $action === 'chargeback') {
            if (! $user->hasRole('suspended')) {
                Log::info("PaddleWebhook: chargeback rejected — {$user->email} is not suspended, skipping.");

                return;
            }
            $this->suspension()->reactivate($user, 'chargeback_rejected', $data);
        }
    }

    // ─── Transaction completed (recurring payment OR one-time license purchase) ─

    protected function transactionCompleted(Request $request): void
    {
        // One-time license purchase: routed by custom_data.intent_id, no
        // subscription attached. Mint the License and stop — token top-up
        // logic below is for plan recurring payments only.
        $intentId = $request->input('data.custom_data.intent_id');
        $linkedSubscription = $request->input('data.subscription_id');

        if ($intentId !== null && $linkedSubscription === null) {
            $transactionId = (string) $request->input('data.id', '');
            $minted = app(LicenseCheckoutService::class)->mintFromTransaction(
                $transactionId,
                ['intent_id' => (int) $intentId]
            );

            if ($minted) {
                Log::info("PaddleWebhook: license minted for intent {$intentId} (txn {$transactionId}, license {$minted->uuid}).");
                addEvent(
                    (object) ['message' => "License minted: {$minted->uuid}", 'id' => $transactionId, 'via' => 'webHook'],
                    'PAYMENT', 'SUCCESS', 'ACTIVITY', 0, 0, $minted->customer_id, $minted->customer_id
                );
            } else {
                Log::warning("PaddleWebhook: license intent {$intentId} could not be minted (txn {$transactionId}).");
            }

            return;
        }

        // For transaction.completed, data.subscription_id carries the subscription.
        // data.id is the transaction ID; we need subscription_id to find the record.
        $subscriptionId = $request->input('data.subscription_id')
            ?? $request->input('data.id');

        if (is_null($subscriptionId)) {
            Log::warning('PaddleWebhook: subscription ID missing in transaction.completed payload.');
            addEvent((object) ['message' => 'Subscription ID missing in transaction.completed', 'id' => '', 'via' => 'webHook'], 'PAYMENT', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

        $paddleSub = PaddleSubscription::where('subscription_id', $subscriptionId)->first();

        if (! $paddleSub) {
            Log::warning("PaddleWebhook: PaddleSubscription not found for subscription_id={$subscriptionId}");
            addEvent((object) ['message' => "Subscription not found: {$subscriptionId}", 'id' => $subscriptionId, 'via' => 'webHook'], 'PAYMENT', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

        $last = Carbon::parse($request->input('occurred_at', now()));
        $next = Carbon::parse($request->input('data.billing_period.ends_at', now()->addDays(30)->toDateTimeString()));

        $paddleSub->last_payment_at = $last;
        $paddleSub->next_payment_at = $next;
        $paddleSub->save();

        $user = config('wave.user_model')::find($paddleSub->user_id);

        if (! $user) {
            Log::warning("PaddleWebhook: user not found for paddleSub user_id={$paddleSub->user_id}");
            addEvent((object) ['message' => "User not found for subscription: {$subscriptionId}", 'id' => $subscriptionId, 'via' => 'webHook'], 'PAYMENT', 'FAILED', 'ACTIVITY', 0, 0, 0, 0);

            return;
        }

        // Seat add-on recurring payment: only update payment dates and notify; do NOT add more seats.
        if ($this->isSeatPlan($paddleSub->plan_id)) {
            $message = "Recurring seat subscription payment received for: {$user->name}.";
            Log::info($message);
            addEvent(
                (object) ['message' => $message, 'id' => $subscriptionId, 'via' => 'webHook'],
                'PAYMENT', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id
            );
            notifyUser($user, __('notifications.seat_purchase_success', [
                'qty' => $paddleSub->quantity ?? 1,
                'total' => optional(Group::where('owner_id', $user->id)->first())->max_members ?? '?',
            ]), 'success');

            return;
        }

        // Top up AI token balance.
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

        $tokenLabel = Number::abbreviate($itokens);
        $message = "Payment received for: {$user->name}. New token balance: {$tokenLabel}";
        Log::info($message);

        addEvent(
            (object) ['message' => $message, 'id' => $subscriptionId, 'via' => 'webHook'],
            'PAYMENT', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id
        );

        // In-app notification.
        if (app()->getLocale() !== $user->locale && $user->locale && array_key_exists($user->locale, config('app.supported_locales', []))) {
            app()->setLocale($user->locale);
        }
        notifyUser($user, __('notifications.subscription_payment_received', ['tokens' => $tokenLabel]), 'success');

        // Email notification.
        event(new SendUserEmail([
            'title' => 'Payment received — thank you!',
            'name' => $user->name,
            'username' => $user->username,
            'uid' => $user->id,
            'from' => 'support@sos-vault.com',
            'email' => $user->email,
            'to' => $user->email,
            'cc' => [],
            'subject' => 'sos-vault — payment received',
            'type' => 'paymentReceived',
            'next_payment_at' => $next->toFormattedDateString(),
            'tokens' => $tokenLabel,
            'attachments' => [],
        ]));
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function findSubscription(?string $vendorSubscriptionId): ?Subscription
    {
        if (is_null($vendorSubscriptionId)) {
            Log::warning('PaddleWebhook: missing subscription ID in payload.');

            return null;
        }

        $subscription = Subscription::where('vendor_subscription_id', $vendorSubscriptionId)->first();

        if (! $subscription) {
            Log::warning("PaddleWebhook: Subscription not found for vendor_subscription_id={$vendorSubscriptionId}");
        }

        return $subscription;
    }

    private function suspension(): AccountSuspensionService
    {
        return app(AccountSuspensionService::class);
    }

    /** Returns true when the given paddle plan_id belongs to a disk expansion plan. */
    private function isDiskPlan(?string $planId): bool
    {
        if (is_null($planId)) {
            return false;
        }

        return Plan::where('type', 'disk')
            ->where('product_id', $planId)
            ->exists();
    }

    /**
     * Cancel a disk-expansion subscription: mark it cancelled, log a CANCELATION event,
     * and notify the user — without changing their account role.
     */
    private function handleDiskCancellation(mixed $user, PaddleSubscription $paddleSub): void
    {
        $message = "Disk subscription cancelled for: {$user->name}.";
        Log::info($message);

        addEvent(
            (object) ['message' => $message, 'id' => $paddleSub->subscription_id, 'via' => 'webHook'],
            'CANCELATION', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id
        );

        notifyUser($user, __('notifications.disk_subscription_cancelled'), 'warning');
    }

    /** Returns true when the given paddle plan_id belongs to a seat add-on plan. */
    private function isSeatPlan(?string $planId): bool
    {
        if (is_null($planId)) {
            return false;
        }

        return Plan::where('type', 'seat')
            ->where('product_id', $planId)
            ->exists();
    }

    /**
     * Decrement max_members by the purchased quantity and suspend any members
     * that now exceed the new limit (newest members suspended first).
     */
    private function handleSeatCancellation(mixed $user, PaddleSubscription $paddleSub, string $reason): void
    {
        $qty = max(1, (int) ($paddleSub->quantity ?? 1));
        $group = Group::where('owner_id', $user->id)->first();

        if (! $group) {
            Log::warning("PaddleWebhook: handleSeatCancellation — no group found for user_id={$user->id}");

            return;
        }

        $newMax = max(1, $group->max_members - $qty);
        $group->update(['max_members' => $newMax]);

        // Suspend over-limit members (newest first = last-in-first-out; never suspend the owner).
        // Order by id ascending so the first-joined members are kept; remaining are newest overflow.
        $available = max(0, $newMax - 1); // owner occupies 1 slot
        $overflow = $group->members()
            ->where('id', '!=', $user->id)
            ->orderBy('id', 'asc')
            ->get()
            ->slice($available);

        $suspendedCount = 0;
        foreach ($overflow as $member) {
            $this->suspension()->suspend($member, $reason, []);
            $suspendedCount++;
        }

        Log::info("PaddleWebhook: seat cancellation — group {$group->id} max_members reduced to {$newMax}, {$suspendedCount} member(s) suspended.");

        addEvent(
            (object) [
                'message' => "Extra seats cancelled: -{$qty} seat(s) for group {$group->name}. {$suspendedCount} account(s) suspended.",
                'id' => $paddleSub->subscription_id,
                'via' => 'webHook',
            ],
            'SEAT_CANCELLED', 'SUCCESS', 'ACTIVITY', 0, 0, $user->id, $user->id
        );

        notifyUser(
            $user,
            __('notifications.seat_cancelled', ['qty' => $qty, 'suspended' => $suspendedCount]),
            'warning'
        );
    }
}
