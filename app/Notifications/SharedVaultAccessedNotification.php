<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SharedVaultAccessedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected User $viewer,
        protected string $link,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'storage/'.($this->viewer->avatar ?? 'avatars/default.png'),
            'status' => 'info',
            'body' => "{$this->viewer->name} opened your shared SOS report.",
            'link' => $this->link,
            'user' => ['name' => $this->viewer->name],
        ];
    }
}
