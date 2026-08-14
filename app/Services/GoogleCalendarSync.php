<?php

namespace App\Services;

use App\Models\CalendarChangeReview;
use App\Models\CalendarConnection;
use App\Models\CampaignMembership;
use App\Models\ExternalCalendarEvent;
use App\Models\Meeting;
use App\Models\SyncCursor;
use App\Notifications\CalendarChangePendingNotification;
use App\Support\Tenancy\ExecutionContextStore;
use Carbon\Carbon;
use Closure;
use Google\Service\Calendar\Event;
use Google\Service\Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class GoogleCalendarSync
{
    public function __construct(
        private readonly GoogleCalendarClientFactory $factory,
        private readonly ExecutionContextStore $contextStore,
    ) {}

    public function sync(CalendarConnection $connection, bool $forceFull = false, ?Closure $heartbeat = null): array
    {
        $this->contextStore->assertCampaign((int) $connection->campaign_id);

        if (! $connection->isReady()) {
            return ['examined' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0];
        }

        $counts = ['examined' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0];

        $stream = 'connection:'.$connection->id.':events';
        $cursor = SyncCursor::query()
            ->where('campaign_id', $connection->campaign_id)
            ->where('calendar_connection_id', $connection->id)
            ->where('system', 'google_calendar')
            ->where('stream', $stream)
            ->first();
        $full = $forceFull || ! $cursor?->cursor;
        $params = [
            'singleEvents' => true,
            'showDeleted' => true,
            'maxResults' => 2500,
        ];
        if ($full) {
            $params['timeMin'] = now()->subDays((int) config('services.google_calendar.past_days', 30))->toRfc3339String();
            $params['timeMax'] = now()->addMonths((int) config('services.google_calendar.future_months', 18))->toRfc3339String();
            $params['orderBy'] = 'startTime';
        } else {
            $params['syncToken'] = $cursor->cursor;
        }

        try {
            $service = $this->factory->service($connection);
            $pageToken = null;
            $nextSyncToken = null;
            do {
                $heartbeat?->__invoke();
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }
                $result = $service->events->listEvents($connection->calendar_id, $params);
                foreach ($result->getItems() as $event) {
                    $outcome = $this->importEvent($connection, $event);
                    $counts['examined']++;
                    $counts[$outcome]++;
                }
                $pageToken = $result->getNextPageToken();
                $nextSyncToken = $result->getNextSyncToken() ?: $nextSyncToken;
            } while ($pageToken);

            $heartbeat?->__invoke();

            SyncCursor::updateOrCreate(
                ['system' => 'google_calendar', 'stream' => $stream],
                [
                    'campaign_id' => $connection->campaign_id,
                    'calendar_connection_id' => $connection->id,
                    'cursor' => $nextSyncToken ?: $cursor?->cursor,
                    'last_synced_at' => now(),
                    'metadata' => [
                        'window_started_at' => $full ? $params['timeMin'] : Arr::get($cursor?->metadata, 'window_started_at'),
                        'window_ends_at' => $full ? $params['timeMax'] : Arr::get($cursor?->metadata, 'window_ends_at'),
                    ],
                ],
            );
            $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

            return $counts;
        } catch (Exception $exception) {
            if (! $full && $exception->getCode() === 410) {
                $cursor?->delete();

                return $this->sync($connection->fresh(), true);
            }
            throw $exception;
        }
    }

    public function normalizeEvent(Event $event, CalendarConnection $connection): array
    {
        $start = $event->getStart();
        $end = $event->getEnd();
        $allDay = filled($start?->getDate());
        $timezone = $start?->getTimeZone() ?: $connection->timezone ?: 'America/Bogota';
        $startsAt = $allDay
            ? Carbon::parse($start->getDate(), $timezone)->startOfDay()
            : ($start?->getDateTime() ? Carbon::parse($start->getDateTime()) : null);
        $endsAt = $allDay
            ? Carbon::parse($end->getDate(), $timezone)->startOfDay()
            : ($end?->getDateTime() ? Carbon::parse($end->getDateTime()) : null);
        $originalStart = $event->getOriginalStartTime();
        $instanceKey = $originalStart?->getDateTime() ?: $originalStart?->getDate() ?: '';

        return [
            'external_event_id' => $event->getId(),
            'recurring_event_id' => $event->getRecurringEventId(),
            'instance_key' => $instanceKey,
            'etag' => $event->getEtag(),
            'title' => $event->getSummary() ?: '(Sin título)',
            'description' => $event->getDescription(),
            'location' => $event->getLocation(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'is_busy' => $event->getTransparency() !== 'transparent',
            'google_status' => $event->getStatus() ?: 'confirmed',
            'html_link' => $event->getHtmlLink(),
            'external_updated_at' => $event->getUpdated() ? Carbon::parse($event->getUpdated()) : null,
            'payload' => [
                'id' => $event->getId(),
                'summary' => $event->getSummary(),
                'description' => $event->getDescription(),
                'location' => $event->getLocation(),
                'status' => $event->getStatus(),
                'transparency' => $event->getTransparency(),
                'start' => $start?->toSimpleObject(),
                'end' => $end?->toSimpleObject(),
                'updated' => $event->getUpdated(),
                'htmlLink' => $event->getHtmlLink(),
                'recurringEventId' => $event->getRecurringEventId(),
                'originalStartTime' => $originalStart?->toSimpleObject(),
                'privateProperties' => $event->getExtendedProperties()?->getPrivate(),
            ],
        ];
    }

    private function importEvent(CalendarConnection $connection, Event $googleEvent): string
    {
        $this->contextStore->assertCampaign((int) $connection->campaign_id);
        $data = $this->normalizeEvent($googleEvent, $connection);
        $private = $googleEvent->getExtendedProperties()?->getPrivate() ?? [];
        $meeting = isset($private['territorio_meeting_id'])
            ? Meeting::where('campaign_id', $connection->campaign_id)->find((int) $private['territorio_meeting_id'])
            : null;

        return DB::transaction(function () use ($connection, $data, $meeting) {
            $event = ExternalCalendarEvent::withTrashed()
                ->where('campaign_id', $connection->campaign_id)
                ->where('calendar_connection_id', $connection->id)
                ->where('external_event_id', $data['external_event_id'])
                ->where('instance_key', $data['instance_key'])
                ->lockForUpdate()
                ->first();
            $before = $event ? $this->reviewPayload($event) : null;
            $cancelled = $data['google_status'] === 'cancelled';

            if (! $event) {
                $event = ExternalCalendarEvent::create([
                    ...$data,
                    'campaign_id' => $connection->campaign_id,
                    'calendar_connection_id' => $connection->id,
                    'meeting_id' => $meeting?->id,
                    'origin' => $meeting ? 'platform' : 'google',
                    'review_status' => $meeting ? 'approved' : 'pending',
                ]);

                if (! $meeting && ! $cancelled) {
                    $this->createReview($connection, $event, 'created', null, $this->reviewPayload($event));
                }

                return 'created';
            }

            if ($event->etag === $data['etag']) {
                return 'unchanged';
            }

            $event->restore();
            $event->fill([
                ...$data,
                'meeting_id' => $meeting?->id ?: $event->meeting_id,
                'review_status' => 'pending',
            ]);
            if ($cancelled) {
                $event->starts_at = $event->getOriginal('starts_at') ?: $event->starts_at;
                $event->ends_at = $event->getOriginal('ends_at') ?: $event->ends_at;
                $event->is_busy = (bool) $event->getOriginal('is_busy');
            }
            $event->save();

            if ($meeting && ! $cancelled && $this->matchesMeeting($event, $meeting)) {
                $event->update(['review_status' => 'approved']);

                return 'updated';
            }

            $this->createReview(
                $connection,
                $event,
                $cancelled ? 'deleted' : 'updated',
                $before,
                $this->reviewPayload($event),
            );

            return 'updated';
        });
    }

    private function createReview(
        CalendarConnection $connection,
        ExternalCalendarEvent $event,
        string $type,
        ?array $before,
        ?array $after,
    ): void {
        $this->contextStore->assertCampaign((int) $connection->campaign_id);
        $this->contextStore->assertCampaign((int) $event->campaign_id);
        if ($event->meeting_id) {
            $this->contextStore->assertCampaign((int) $event->meeting?->campaign_id);
        }

        CalendarChangeReview::where('external_calendar_event_id', $event->id)
            ->where('campaign_id', $connection->campaign_id)
            ->where('status', 'pending')
            ->update(['status' => 'superseded']);

        $fingerprint = hash('sha256', implode('|', [
            $connection->id,
            $event->external_event_id,
            $event->instance_key,
            $type,
            $event->etag,
        ]));
        $review = CalendarChangeReview::firstOrCreate(
            ['calendar_connection_id' => $connection->id, 'fingerprint' => $fingerprint],
            [
                'public_id' => (string) Str::ulid(),
                'campaign_id' => $connection->campaign_id,
                'external_calendar_event_id' => $event->id,
                'meeting_id' => $event->meeting_id,
                'change_type' => $type,
                'before_payload' => $before,
                'after_payload' => $after,
                'status' => 'pending',
            ],
        );

        if ($review->wasRecentlyCreated) {
            DB::afterCommit(fn () => $this->notifyReviewers($review));
        }
    }

    private function notifyReviewers(CalendarChangeReview $review): void
    {
        $this->contextStore->assertCampaign((int) $review->campaign_id);
        CampaignMembership::with(['user', 'role'])
            ->where('campaign_id', $review->campaign_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (CampaignMembership $membership) => $membership->can('calendar.changes.review'))
            ->each(fn (CampaignMembership $membership) => $membership->user?->notify(
                new CalendarChangePendingNotification($review->id)
            ));
    }

    private function matchesMeeting(ExternalCalendarEvent $event, Meeting $meeting): bool
    {
        return $event->google_status !== 'cancelled'
            && $event->title === $meeting->title
            && $event->starts_at?->equalTo($meeting->starts_at)
            && $event->ends_at?->equalTo($meeting->ends_at)
            && trim((string) $event->location) === trim((string) ($meeting->address ?: $meeting->location));
    }

    private function reviewPayload(ExternalCalendarEvent $event): array
    {
        return [
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'all_day' => $event->all_day,
            'is_busy' => $event->is_busy,
            'google_status' => $event->google_status,
            'html_link' => $event->html_link,
        ];
    }
}
