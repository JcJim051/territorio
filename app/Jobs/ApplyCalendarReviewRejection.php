<?php

namespace App\Jobs;

use App\Models\CalendarChangeReview;
use App\Models\ExternalCalendarEvent;
use App\Services\GoogleCalendarClientFactory;
use App\Services\GoogleCalendarPublisher;
use Google\Service\Calendar\Event;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyCalendarReviewRejection implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public array $backoff = [30, 60, 180, 600, 1800];

    public function __construct(public readonly int $reviewId)
    {
        $this->afterCommit();
    }

    public function handle(GoogleCalendarClientFactory $factory, GoogleCalendarPublisher $publisher): void
    {
        $review = CalendarChangeReview::with(['connection', 'event', 'meeting'])->find($this->reviewId);
        if (! $review || $review->status !== 'rejected' || ! $review->connection?->isReady()) {
            return;
        }

        $event = $review->event;
        if (! $event) {
            return;
        }

        if ($review->meeting) {
            $publisher->upsert($review->meeting);
            $event->forceFill(['review_status' => 'approved'])->save();

            return;
        }

        $service = $factory->service($review->connection);
        if ($review->change_type === 'created') {
            try {
                $service->events->delete($review->connection->calendar_id, $event->external_event_id);
            } catch (\Google\Service\Exception $exception) {
                if (! in_array($exception->getCode(), [404, 410], true)) {
                    throw $exception;
                }
            }
            $event->delete();

            return;
        }

        $before = $review->before_payload ?? [];
        $googleEvent = new Event([
            'summary' => $before['title'] ?? '(Sin título)',
            'description' => $before['description'] ?? null,
            'location' => $before['location'] ?? null,
            'transparency' => ($before['is_busy'] ?? true) ? 'opaque' : 'transparent',
            'start' => $this->datePayload($before, 'starts_at'),
            'end' => $this->datePayload($before, 'ends_at'),
        ]);

        if ($review->change_type === 'deleted') {
            $saved = $service->events->insert($review->connection->calendar_id, $googleEvent);
            $event->external_event_id = $saved->getId();
            $event->etag = $saved->getEtag();
            $event->html_link = $saved->getHtmlLink();
        } else {
            $saved = $service->events->update($review->connection->calendar_id, $event->external_event_id, $googleEvent);
            $event->etag = $saved->getEtag();
        }
        $event->forceFill([
            'title' => $before['title'] ?? $event->title,
            'location' => $before['location'] ?? $event->location,
            'starts_at' => $before['starts_at'] ?? $event->starts_at,
            'ends_at' => $before['ends_at'] ?? $event->ends_at,
            'all_day' => $before['all_day'] ?? false,
            'is_busy' => $before['is_busy'] ?? true,
            'google_status' => 'confirmed',
            'review_status' => 'approved',
        ])->save();
    }

    private function datePayload(array $payload, string $key): array
    {
        if ($payload['all_day'] ?? false) {
            return ['date' => substr((string) ($payload[$key] ?? ''), 0, 10)];
        }

        return [
            'dateTime' => $payload[$key] ?? now()->toRfc3339String(),
            'timeZone' => 'America/Bogota',
        ];
    }
}
