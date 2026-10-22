<?php

namespace App\Notifications;

use App\Models\Alert;
use App\Models\AlertTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AlertTriggeredNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Alert $alert,
        protected AlertTrigger $trigger,
        protected string $link,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'phosphor-bell-ringing-duotone',
            'status' => $this->statusFor($this->alert->severity),
            'body' => "[{$this->alert->severity}] {$this->alert->name}: {$this->alert->description}",
            'link' => $this->link,
            'user' => ['name' => $this->alert->user?->name],
        ];
    }

    private function statusFor(string $severity): string
    {
        return match ($severity) {
            'INFO' => 'info',
            'WARNING' => 'warning',
            default => 'danger',
        };
    }
}
