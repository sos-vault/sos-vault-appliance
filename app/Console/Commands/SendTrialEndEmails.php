<?php

namespace App\Console\Commands;

use App\Events\SendUserEmail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendTrialEndEmails extends Command
{
    protected $signature = 'users:send-trial-end-emails';

    protected $description = 'Email trial users (role_id=7) whose trial ends within the next 7 days';

    public function handle(): int
    {
        if (! setting('site.trial_end_emails')) {
            return self::SUCCESS;
        }

        // users on trial that have 7 days or less of trial
        $today = Carbon::today();
        $date = Carbon::today()->addDays(7);
        $users = User::where('role_id', 7)
            ->wheredate('trial_ends_at', '>', $today)
            ->wheredate('trial_ends_at', '<=', $date)
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            $title = 'End of Trial with sos-vault!.';
            $subject = 'sos-vault End of Trial';
            $from = 'support@sos-vault.com';
            $data = [
                'title' => $title,
                'name' => $user->name,
                'username' => $user->username,
                'uid' => $user->id,
                'email' => $user->email,
                'to' => $user->email,
                'from' => $from,
                'plan' => $user->role->display_name,
                'daysleft' => $user->daysLeftOnTrial(),
                'enddate' => Carbon::parse($user->trial_ends_at)->format('D d M Y'),
                'deletedate' => Carbon::parse($user->trial_ends_at)->addDays(7)->format('D d M Y'),
                'since' => $user->created_at,
                'body' => '',
                'subject' => $subject,
                'type' => 'endOfTrial',
            ];

            SendUserEmail::dispatch($data);
            $sent++;
        }

        if ($sent > 0) {
            addEvent(
                (object) ['message' => "users:send-trial-end-emails dispatched {$sent} end-of-trial email(s)"],
                'SCHEDULER', 'SUCCESS', 'ACTIVITY', 0, 0, 0, 0
            );
        }

        return self::SUCCESS;
    }
}
