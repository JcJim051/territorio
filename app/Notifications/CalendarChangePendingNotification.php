<?php

namespace App\Notifications;

use App\Models\CalendarChangeReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CalendarChangePendingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $reviewId)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $review = CalendarChangeReview::with(['event', 'connection'])->find($this->reviewId);
        $title = $review?->event?->title ?: 'Cambio en Google Calendar';

        return (new MailMessage())
            ->subject('Decisión pendiente en la agenda')
            ->greeting('Hola, '.$notifiable->name)
            ->line('Google Calendar reportó un cambio que ya bloquea provisionalmente la agenda.')
            ->line($title)
            ->action('Revisar cambio', url('/calendar/reviews?status=pending'))
            ->line('El cambio no quedará autorizado hasta resolver posibles cruces y aprobarlo.');
    }
}
