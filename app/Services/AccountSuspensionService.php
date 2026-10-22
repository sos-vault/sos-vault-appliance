<?php

namespace App\Services;

use App\Events\SendUserEmail;
use App\Models\Sysevent;
use App\Models\User;
use App\Providers\VaultTools;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Wave\Subscription;

class AccountSuspensionService
{
    public function __construct(private readonly TelegramService $telegram) {}

    /**
     * Suspend a user account: assign suspended role, close vault, notify admin via Telegram,
     * send suspension email to the user, and log a sysevent.
     *
     * @param  string  $reason  'cancellation' | 'refund' | 'chargeback'
     * @param  array<string, mixed>  $eventPayload  Raw Paddle webhook payload for audit trail
     */
    public function suspend(User $user, string $reason, array $eventPayload = []): void
    {
        if ($reason === 'cancellation') {
            // Voluntary cancellation: assign 'cancelled' role (keeps vault open).
            $cancelledRole = Role::where('name', 'cancelled')->first();
            $user->syncRoles(['cancelled']);
            if ($cancelledRole) {
                $user->forceFill(['role_id' => $cancelledRole->id])->save();
            }

            $this->logSysevent($user, 'CANCELATION', $reason, $eventPayload);

            notifyUser($user, __('notifications.subscription_cancelled'), 'warning');

            $this->notifyTelegram($user, $reason, 'cancelled');

            $this->sendEmail($user, $reason, 'accountSuspended');

            Log::warning("AccountSuspensionService: user {$user->email} subscription cancelled.");

            return;
        }

        // Forced suspension (chargeback, refund, seat_cancellation, etc.)
        $user->syncRoles(['suspended']);

        $this->closeUserVault($user);

        $this->logSysevent($user, 'BILLING_SUSPENSION', $reason, $eventPayload);

        $this->notifyTelegram($user, $reason, 'suspended');

        $this->sendEmail($user, $reason, 'accountSuspended');

        Log::warning("AccountSuspensionService: user {$user->email} suspended. Reason: {$reason}");
    }

    /**
     * Reactivate a previously suspended user: restore plan role from active subscription
     * (or fall back to 'Free'), notify admin, send reactivation email, and log a sysevent.
     *
     * @param  string  $reason  'admin_action' | 'chargeback_rejected' | 'subscription_activated'
     * @param  array<string, mixed>  $eventPayload  Raw Paddle webhook payload for audit trail
     */
    public function reactivate(User $user, string $reason, array $eventPayload = []): void
    {
        $roleName = $this->resolveRoleFromSubscription($user);

        $user->syncRoles([$roleName]);

        $this->logSysevent($user, 'BILLING_REACTIVATION', $reason, $eventPayload);

        $this->notifyTelegram($user, $reason, 'reactivated');

        $this->sendEmail($user, $reason, 'accountReactivated');

        Log::info("AccountSuspensionService: user {$user->email} reactivated as '{$roleName}'. Reason: {$reason}");
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function closeUserVault(User $user): void
    {
        try {
            $vtools = new VaultTools($user);
            if ($vtools->vaultExists() && $vtools->isOpen()) {
                // Force past always_open: a forced suspension must lock the user
                // out, even if their vault was pinned open.
                $vtools->closeVault(0, true);
            }
        } catch (\Throwable $e) {
            Log::error("AccountSuspensionService: could not close vault for {$user->email}: {$e->getMessage()}");
        }
    }

    private function resolveRoleFromSubscription(User $user): string
    {
        $subscription = Subscription::where('billable_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan.role')
            ->latest()
            ->first();

        $roleName = $subscription?->plan?->role?->name ?? config('wave.default_user_role', 'Free');

        return $roleName ?: 'Free';
    }

    /** @param array<string, mixed> $eventPayload */
    private function logSysevent(User $user, string $type, string $reason, array $eventPayload): void
    {
        try {
            Sysevent::create([
                'vault_id' => $user->vault?->id ?? 0,
                'dir_id' => 0,
                'case_id' => 0,
                'status' => 'SUCCESS',
                'type' => $type,
                'class' => 'BILLING',
                'payload' => json_encode(['reason' => $reason, 'event' => $eventPayload]),
                'owner' => $user->id,
                'group' => $user->group_id ?? 0,
                'ip' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error("AccountSuspensionService: sysevent log failed for {$user->email}: {$e->getMessage()}");
        }
    }

    private function notifyTelegram(User $user, string $reason, string $action): void
    {
        try {
            $icon = match ($action) {
                'suspended', 'cancelled' => '🚫', default => '✅'
            };
            $label = match ($action) {
                'suspended' => 'SUSPENDED', 'cancelled' => 'CANCELLED', default => 'REACTIVATED'
            };
            $reasonLabel = match ($reason) {
                'cancellation' => 'Subscription cancellation',
                'refund' => 'Payment refund approved',
                'chargeback' => 'Chargeback approved',
                'chargeback_rejected' => 'Chargeback rejected (merchant won)',
                'subscription_activated' => 'Subscription re-activated via Paddle',
                default => ucfirst(str_replace('_', ' ', $reason)),
            };

            $message = "{$icon} Account {$label}\n"
                ."User: {$user->name} <{$user->email}>\n"
                ."Reason: {$reasonLabel}\n"
                .'App: '.config('app.url');

            $this->telegram->sendTelegramMessage($message);
        } catch (\Throwable $e) {
            Log::error("AccountSuspensionService: Telegram notification failed: {$e->getMessage()}");
        }
    }

    private function sendEmail(User $user, string $reason, string $type): void
    {
        try {
            $subjectMap = [
                'accountSuspended' => 'Your sos-vault account has been suspended',
                'accountReactivated' => 'Your sos-vault account has been reactivated',
            ];

            event(new SendUserEmail([
                'title' => $subjectMap[$type],
                'name' => $user->name,
                'username' => $user->username,
                'uid' => $user->id,
                'from' => 'support@sos-vault.com',
                'email' => $user->email,
                'to' => $user->email,
                'cc' => [],
                'subject' => $subjectMap[$type],
                'type' => $type,
                'reason' => $reason,
                'attachments' => [],
            ]));
        } catch (\Throwable $e) {
            Log::error("AccountSuspensionService: email send failed for {$user->email}: {$e->getMessage()}");
        }
    }
}
