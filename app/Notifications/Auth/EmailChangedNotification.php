<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the PREVIOUS address when the account e-mail changes. */
class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.email_changed.subject', ['app' => config('linklibrary.brand.name')]))
            ->line(__('mail.email_changed.line'))
            ->line(__('mail.password_changed.contact', ['email' => config('linklibrary.brand.support_email')]));
    }
}
