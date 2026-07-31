<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Models\OutboxEvent;
use App\Services\GoogleCalendarPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProcessCalendarOutbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public array $backoff = [30, 60, 120, 300, 600, 1800];

    public function handle(GoogleCalendarPublisher $publisher): void
    {
        $lock = Cache::lock('google-calendar:outbox', 110);
        if (! $lock->get()) {
            return;
        }

        try {
            OutboxEvent::query()
                ->whereNull('published_at')
                ->whereIn('type', ['calendar.meeting.upsert', 'calendar.meeting.delete'])
                ->orderBy('id')
                ->limit(100)
                ->get()
                ->each(function (OutboxEvent $event) use ($publisher) {
                    $meeting = Meeting::find($event->aggregate_id);
                    try {
                        if ($event->type === 'calendar.meeting.delete') {
                            $publisher->deleteByIdentifiers(
                                (int) ($event->payload['campaign_id'] ?? $event->campaign_id),
                                (int) ($event->payload['meeting_id'] ?? $event->aggregate_id),
                            );
                        } elseif ($meeting) {
                            $publisher->upsert($meeting);
                        }
                        $event->forceFill(['published_at' => now(), 'last_error' => null])->save();
                    } catch (\Throwable $exception) {
                        $event->increment('attempts');
                        $event->forceFill(['last_error' => str($exception->getMessage())->limit(4000)])->save();
                        throw $exception;
                    }
                });
        } finally {
            $lock->release();
        }
    }
}
