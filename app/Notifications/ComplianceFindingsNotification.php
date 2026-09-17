<?php

namespace App\Notifications;

use App\Models\AnalysisRun;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class ComplianceFindingsNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected AnalysisRun $run,
        protected SupportCase $case,
        protected Collection $findings,
        protected string $link,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $count = $this->findings->count();
        $worst = $this->findings->contains('severity', 'CRITICAL') ? 'CRITICAL' : 'HIGH';

        return [
            'icon' => 'phosphor-shield-warning-duotone',
            'status' => $this->statusFor($worst),
            'body' => "[{$worst}] {$count} compliance finding(s) failed for case {$this->case->case}",
            'link' => $this->link,
            'user' => ['name' => $this->case->owner ? User::find($this->case->owner)?->name : null],
        ];
    }

    private function statusFor(string $severity): string
    {
        return match ($severity) {
            'CRITICAL' => 'danger',
            default => 'warning',
        };
    }
}
