<?php

namespace App\Notifications\Auth;

use App\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sends a frontend link that carries the backend's temporary signed verification URL parameters.
 */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function signedParameters(object $notifiable): array
    {
        $signed = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $notifiable->public_id,
            'hash' => sha1((string) $notifiable->getEmailForVerification()),
        ], absolute: false);

        $query = [];
        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return [
            'id' => (string) $notifiable->public_id,
            'hash' => sha1((string) $notifiable->getEmailForVerification()),
            'expires' => (string) ($query['expires'] ?? ''),
            'signature' => (string) ($query['signature'] ?? ''),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = FrontendUrl::to('verify_email', [], self::signedParameters($notifiable));

        return (new MailMessage)
            ->subject(__('mail.verify.subject', ['app' => config('linklibrary.brand.name')]))
            ->line(__('mail.verify.line'))
            ->action(__('mail.verify.action'), $url)
            ->line(__('mail.verify.ignore'));
    }
}
