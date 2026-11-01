<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\FrontendUrl;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation e-mail. Contains the plaintext token (the only place it ever exists) but deliberately
 * not the link's title or URL. The database copy never contains the token.
 */
class LinkInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        public readonly string $invitationId,
        private readonly string $inviterName,
        private readonly CarbonInterface $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = FrontendUrl::to('accept_invitation', [], ['token' => $this->token]);

        return (new MailMessage)
            ->subject(__('mail.invitation.subject', ['name' => $this->inviterName, 'app' => config('linklibrary.brand.name')]))
            ->line(__('mail.invitation.line', ['name' => $this->inviterName]))
            ->action(__('mail.invitation.action'), $url)
            ->line(__('mail.invitation.expires', ['date' => $this->expiresAt->toDayDateTimeString()]))
            ->line(__('mail.invitation.verify_note'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'sharing.invitation_received',
            'invitation_id' => $this->invitationId,
            'inviter_name' => $this->inviterName,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }

    /** Test helper: the token is needed to simulate clicking the e-mail link. */
    public function tokenForTesting(): string
    {
        return $this->token;
    }
}
