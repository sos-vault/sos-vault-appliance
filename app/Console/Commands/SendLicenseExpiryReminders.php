<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendLicenseExpiryReminders extends Command
{
    protected $signature = 'license:send-expiry-reminders
                            {--dry-run : Print what would be sent without actually sending}';

    protected $description = 'Email customers whose licenses are about to expire (30/15/7/daily thresholds)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = now()->startOfDay();

        $reminded = 0;

        // Yearly licenses only: 30-day notice. A license is "yearly" if its duration
        // is more than 31 days (to avoid false positives for monthly licenses).
        $licenses = License::active()
            ->whereDate('expires_at', $today->copy()->addDays(30))
            ->get()
            ->filter(fn (License $l) => $l->issued_at->diffInDays($l->expires_at) > 31);

        foreach ($licenses as $license) {
            $this->remind($license, '30 days', $dryRun);
            $reminded++;
        }

        // 15-day notice for all licenses (monthly and yearly).
        foreach (License::active()->whereDate('expires_at', $today->copy()->addDays(15))->get() as $license) {
            $this->remind($license, '15 days', $dryRun);
            $reminded++;
        }

        // Daily reminders for the final 7 days (including 7, 6, 5, ..., 1, 0).
        for ($days = 7; $days >= 0; $days--) {
            foreach (License::active()->whereDate('expires_at', $today->copy()->addDays($days))->get() as $license) {
                $label = $days === 0 ? 'today' : ($days === 1 ? 'tomorrow' : "{$days} days");
                $this->remind($license, $label, $dryRun);
                $reminded++;
            }
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Processed {$reminded} reminder(s).");

        if ($reminded > 0 && ! $dryRun) {
            addEvent(
                (object) ['message' => "license:send-expiry-reminders sent {$reminded} reminder(s)"],
                'SCHEDULER', 'SUCCESS', 'ACTIVITY', 0, 0, 0, 0
            );
        }

        return self::SUCCESS;
    }

    private function remind(License $license, string $label, bool $dryRun): void
    {
        $user = $license->customer;
        if (! $user) {
            return;
        }

        $this->line(" → {$user->email}  license={$license->uuid}  expires in {$label}");

        if ($dryRun) {
            return;
        }

        $subject = "SOS-Vault license expires in {$label}";
        $renewUrl = url("/portal/licenses/{$license->id}");

        $body = "Your SOS-Vault license is set to expire in {$label}.\n\n"
            ."License ID: {$license->uuid}\n"
            ."Seats: {$license->seats}\n"
            ."Expires: {$license->expires_at->toDateString()}\n\n"
            ."Renew now: {$renewUrl}\n\n"
            .'After expiry, the appliance will enter a grace period before features lock. '
            .'Renew to avoid interruption.';

        Mail::raw($body, fn ($m) => $m->to($user->email)->subject($subject));
    }
}
