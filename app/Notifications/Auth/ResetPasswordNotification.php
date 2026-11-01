<?php

namespace App\Notifications\Auth;

use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = FrontendUrl::to('reset_password', [], ['token' => $this->token, 'email' => (string) $notifiable->email]);

        return (new MailMessage)
            ->subject(__('mail.reset.subject', ['app' => config('linklibrary.brand.name')]))
            ->line(__('mail.reset.line'))
            ->action(__('mail.reset.action'), $url)
            ->line(__('mail.reset.expire', ['count' => config('auth.passwords.users.expire')]))
            ->line(__('mail.reset.ignore'));
    }
}
