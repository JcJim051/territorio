<?php

namespace App\Services;

use App\Enums\CalendarPublicationResult;
use App\Exceptions\CalendarPublicationDeferred;
use App\Exceptions\GoogleCalendarSafeFailure;
use App\Models\CalendarConnection;
use App\Models\ExternalCalendarEvent;
use App\Models\IntegrationMapping;
use App\Models\Meeting;
use App\Support\Tenancy\ExecutionContextStore;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Support\Facades\DB;

class GoogleCalendarPublisher
{
    public function __construct(
        private readonly GoogleCalendarClientFactory $factory,
        private readonly ExecutionContextStore $contextStore,
    ) {}

    public function upsert(Meeting $meeting): CalendarPublicationResult
    {
        $this->contextStore->assertCampaign((int) $meeting->campaign_id);
        if ($meeting->status !== 'approved') {
            return CalendarPublicationResult::TerminalNoop;
        }

        $connection = CalendarConnection::query()
            ->where('campaign_id', $meeting->campaign_id)
            ->where('status', 'active')
            ->first();

        if (! $connection?->isReady()) {
            throw new CalendarPublicationDeferred;
        }

        $service = $this->factory->service($connection);
        $mapping = IntegrationMapping::query()
            ->where('campaign_id', $meeting->campaign_id)
            ->where('system', 'google_calendar')
            ->where('entity_type', 'meeting')
            ->where('local_id', (string) $meeting->id)
            ->first();

        $event = new Event($this->payload($meeting));
        $mappingBelongsToActiveCalendar = $mapping
            && (string) data_get($mapping->metadata, 'calendar_id') === (string) $connection->calendar_id;

        if ($mappingBelongsToActiveCalendar) {
            try {
                $saved = $service->events->update($connection->calendar_id, $mapping->external_id, $event);
            } catch (GoogleServiceException $exception) {
                if (! in_array($exception->getCode(), [404, 410], true)) {
                    throw $exception;
                }
                $saved = $this->insertIdempotently($service, $connection, $meeting, $event);
            }
        } else {
            $saved = $this->insertIdempotently($service, $connection, $meeting, $event);
        }

        $normalized = app(GoogleCalendarSync::class)->normalizeEvent($saved, $connection);
        DB::transaction(function () use ($connection, $mapping, $meeting, $normalized, $saved) {
            if ($mapping) {
                $mapping->update([
                    'external_id' => $saved->getId(),
                    'metadata' => ['calendar_connection_id' => $connection->id, 'calendar_id' => $connection->calendar_id],
                ]);
            } else {
                IntegrationMapping::create([
                    'campaign_id' => $meeting->campaign_id,
                    'system' => 'google_calendar',
                    'entity_type' => 'meeting',
                    'local_id' => (string) $meeting->id,
                    'external_id' => $saved->getId(),
                    'metadata' => ['calendar_connection_id' => $connection->id, 'calendar_id' => $connection->calendar_id],
                ]);
            }

            $meeting->forceFill([
                'google_event_id' => $saved->getId(),
                'google_etag' => $saved->getEtag(),
            ])->saveQuietly();

            ExternalCalendarEvent::withTrashed()->updateOrCreate(
                [
                    'campaign_id' => $meeting->campaign_id,
                    'calendar_connection_id' => $connection->id,
                    'external_event_id' => $saved->getId(),
                    'instance_key' => $normalized['instance_key'],
                ],
                [
                    ...$normalized,
                    'campaign_id' => $meeting->campaign_id,
                    'meeting_id' => $meeting->id,
                    'origin' => 'platform',
                    'review_status' => 'approved',
                    'deleted_at' => null,
                ],
            );
        });

        return CalendarPublicationResult::Confirmed;
    }

    public function delete(Meeting $meeting): CalendarPublicationResult
    {
        return $this->deleteByIdentifiers($meeting->campaign_id, $meeting->id);
    }

    public function deleteByIdentifiers(int $campaignId, int $meetingId): CalendarPublicationResult
    {
        $this->contextStore->assertCampaign($campaignId);
        $connection = CalendarConnection::query()
            ->where('campaign_id', $campaignId)
            ->where('status', 'active')
            ->first();
        $mapping = IntegrationMapping::query()
            ->where('campaign_id', $campaignId)
            ->where('system', 'google_calendar')
            ->where('entity_type', 'meeting')
            ->where('local_id', (string) $meetingId)
            ->first();

        if (! $mapping) {
            return CalendarPublicationResult::TerminalNoop;
        }
        if (
            ! $connection?->isReady()
            || (string) data_get($mapping->metadata, 'calendar_id') !== (string) $connection->calendar_id
        ) {
            throw new CalendarPublicationDeferred;
        }

        try {
            $this->factory->service($connection)->events->delete($connection->calendar_id, $mapping->external_id);
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 404 && $exception->getCode() !== 410) {
                throw $exception;
            }
        }

        ExternalCalendarEvent::where('calendar_connection_id', $connection->id)
            ->where('campaign_id', $campaignId)
            ->where('external_event_id', $mapping->external_id)
            ->delete();
        $mapping->delete();

        return CalendarPublicationResult::Confirmed;
    }

    private function insertIdempotently(
        Calendar $service,
        CalendarConnection $connection,
        Meeting $meeting,
        Event $event,
    ): Event {
        $eventId = 't'.substr(hash('sha256', implode('|', [
            'territorio',
            $meeting->campaign_id,
            $meeting->public_id,
            $connection->calendar_id,
        ])), 0, 63);
        $event->setId($eventId);

        try {
            return $service->events->insert($connection->calendar_id, $event);
        } catch (GoogleServiceException $exception) {
            if ($exception->getCode() !== 409) {
                throw $exception;
            }
        }

        $saved = $service->events->get($connection->calendar_id, $eventId);
        $private = $saved->getExtendedProperties()?->getPrivate() ?? [];
        if (
            (string) ($private['territorio_campaign_id'] ?? '') !== (string) $meeting->campaign_id
            || (string) ($private['territorio_meeting_id'] ?? '') !== (string) $meeting->id
            || (string) ($private['territorio_meeting_public_id'] ?? '') !== (string) $meeting->public_id
        ) {
            throw new GoogleCalendarSafeFailure(
                'idempotency_collision',
                'Google Calendar devolvió un identificador ocupado por otro evento.',
                false,
            );
        }

        return $saved;
    }

    private function payload(Meeting $meeting): array
    {
        $campaign = $meeting->campaign;
        $lines = [
            'Actividad gestionada desde Territorio.',
            'Tipo: '.ucfirst($meeting->type),
            'Asistencia estimada: '.$meeting->expected_attendees,
            'Campaña: '.$campaign->name,
            url('/meetings?status=approved&month='.$meeting->starts_at->format('Y-m')),
        ];

        return [
            'summary' => $meeting->title,
            'description' => implode("\n", $lines),
            'location' => $meeting->address ?: $meeting->location,
            'visibility' => 'private',
            'transparency' => 'opaque',
            'start' => [
                'dateTime' => $meeting->starts_at->toRfc3339String(),
                'timeZone' => $campaign->settings['timezone'] ?? 'America/Bogota',
            ],
            'end' => [
                'dateTime' => $meeting->ends_at->toRfc3339String(),
                'timeZone' => $campaign->settings['timezone'] ?? 'America/Bogota',
            ],
            'reminders' => ['useDefault' => true],
            'extendedProperties' => [
                'private' => [
                    'territorio_campaign_id' => (string) $meeting->campaign_id,
                    'territorio_meeting_id' => (string) $meeting->id,
                    'territorio_meeting_public_id' => $meeting->public_id,
                    'territorio_version' => (string) $meeting->updated_at?->getTimestamp(),
                ],
            ],
        ];
    }
}
