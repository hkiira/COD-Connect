<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Generic bell notification: a title, a sentence, and the dashboard page to open. */
class AppAlert extends Notification
{
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $link = null,
        private readonly ?string $category = null,
        private readonly ?string $type = null,
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title'    => $this->title,
            'message'  => $this->message,
            'link'     => $this->link,
            'category' => $this->category,
            'type'     => $this->type,
        ];
    }
}
