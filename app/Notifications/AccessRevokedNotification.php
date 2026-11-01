<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Tells a viewer that their access to a shared link ended (no link details are included). */
class AccessRevokedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $savedLinkId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'sharing.access_revoked', 'link_id' => $this->savedLinkId];
    }
}
