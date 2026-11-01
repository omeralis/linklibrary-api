<?php

namespace App\Notifications;

use App\Enums\SecurityStatus;
use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $savedLinkId, public readonly SecurityStatus $status) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.security_warning.subject', ['app' => config('linklibrary.brand.name')]))
            ->line(__('mail.security_warning.line_'.strtolower($this->status->value)))
            ->action(__('mail.security_warning.action'), FrontendUrl::link($this->savedLinkId))
            ->line(__('mail.security_warning.appeal'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'security.warning', 'link_id' => $this->savedLinkId, 'security_status' => $this->status->value];
    }
}
