<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ActionNotification extends Notification
{
    use Queueable;
    private $user;
    private $body;

    /**
     * Create a new notification instance.
     */
    public function __construct($user, $data) {
        $this->user = $user;
        $this->data = $data;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage {
        return (new MailMessage)
            ->line("Hi {$this->data->email->user->name}!")
            ->line("")
            ->line("")
            ->line("You have a notification from sos-vault.")
            ->line("")
            ->line($this->data->email->lines)
            ->line("")
            ->line("")
            ->line("If you have any questions or need a hand, feel free to contact our support team in support@sos-vault.com we're here to help.")
            ->line("")
            ->line("")
            ->line("Thanks for using sos-vault!")
            ->line("");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array {
        return $this->data->toarray;
    }
}

