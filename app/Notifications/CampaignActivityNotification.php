<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignActivityNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $campaignId,
        public readonly string $title,
        public readonly string $message,
        public readonly string $href,
        public readonly string $category = 'general',
        public readonly bool $sendMail = false,
        public readonly ?string $mailSubject = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $this->sendMail ? ['database', 'mail'] : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'campaign_id' => $this->campaignId,
            'title' => $this->title,
            'message' => $this->message,
            'href' => $this->href,
            'category' => $this->category,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject($this->mailSubject ?? $this->title)
            ->greeting('Hola, '.$notifiable->name)
            ->line($this->message)
            ->action('Abrir en Territorio', url($this->href));
    }
}
