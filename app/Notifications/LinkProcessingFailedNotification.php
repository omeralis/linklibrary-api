<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class LinkProcessingFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $savedLinkId, public readonly string $stage, public readonly ?string $errorCode) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'processing.failed', 'link_id' => $this->savedLinkId, 'stage' => $this->stage, 'error_code' => $this->errorCode];
    }
}
