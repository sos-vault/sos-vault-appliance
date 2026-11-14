<?php

namespace App\Services;

use App\Models\License;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LicenseRevocationService
{
    /**
     * Revoke all active licenses linked to a subscription.
     * Used when Paddle reports a refund, chargeback, or hard cancellation.
     *
     * @return Collection<int, License> the licenses that were revoked
     */
    public function revokeForSubscription(int $subscriptionId, string $reason, bool $notify = true): Collection
    {
        $licenses = License::where('subscription_id', $subscriptionId)
            ->active()
            ->get();

        foreach ($licenses as $license) {
            $this->revoke($license, $reason, $notify);
        }

        return $licenses;
    }

    /**
     * Mark active licenses for a subscription as EXPIRED at their period end.
     * Used when Paddle reports a soft cancellation (subscription stays valid until the end of the paid period).
     *
     * @return Collection<int, License>
     */
    public function expireForSubscription(int $subscriptionId, bool $notify = true): Collection
    {
        $licenses = License::where('subscription_id', $subscriptionId)
            ->active()
            ->get();

        foreach ($licenses as $license) {
            $license->status = 'EXPIRED';
            $license->save();

            Log::info("License {$license->uuid} marked EXPIRED (subscription cancelled).");

            if ($notify) {
                $this->notify($license, 'cancellation');
            }
        }

        return $licenses;
    }

    /**
     * Revoke a single license and (optionally) notify the customer.
     */
    public function revoke(License $license, string $reason, bool $notify = true): void
    {
        $license->status = 'REVOKED';
        $license->revocation_reason = $reason;
        $license->signed_license = null;
        $license->save();

        Log::warning("License {$license->uuid} REVOKED. Reason: {$reason}");

        if ($notify) {
            $this->notify($license, $reason);
        }
    }

    private function notify(License $license, string $reason): void
    {
        $user = $license->customer;
        if (! $user) {
            return;
        }

        $subject = match ($reason) {
            'refund' => 'Your SOS-Vault license has been revoked (payment refunded)',
            'chargeback' => 'Your SOS-Vault license has been revoked (chargeback)',
            'cancellation' => 'Your SOS-Vault license will expire at the end of the current period',
            default => 'Your SOS-Vault license status has changed',
        };

        $body = "License ID: {$license->uuid}\n"
            ."Status: {$license->status}\n"
            ."Reason: {$reason}\n\n"
            .'Contact support if you believe this was in error.';

        Mail::raw($body, fn ($m) => $m->to($user->email)->subject($subject));
    }
}
