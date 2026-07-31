<?php

namespace App\Services;

use App\Models\CalendarConnection;
use App\Models\ExternalCalendarEvent;
use App\Models\IntegrationMapping;
use App\Models\Meeting;
use Google\Service\Calendar\Event;

class GoogleCalendarPublisher
{
    public function __construct(private readonly GoogleCalendarClientFactory $factory)
    {
    }

    public function upsert(Meeting $meeting): void
    {
        $connection = CalendarConnection::query()
            ->where('campaign_id', $meeting->campaign_id)
            ->where('status', 'active')
            ->first();

        if (! $connection?->isReady() || $meeting->status !== 'approved') {
            return;
        }

        $service = $this->factory->service($connection);
        $mapping = IntegrationMapping::query()
            ->where('campaign_id', $meeting->campaign_id)
            ->where('system', 'google_calendar')
            ->where('entity_type', 'meeting')
            ->where('local_id', (string) $meeting->id)
            ->first();

        $event = new Event($this->payload($meeting));
        if ($mapping) {
            try {
                $saved = $service->events->update($connection->calendar_id, $mapping->external_id, $event);
            } catch (\Google\Service\Exception $exception) {
                if (! in_array($exception->getCode(), [404, 410], true)) {
                    throw $exception;
                }
                $saved = $service->events->insert($connection->calendar_id, $event);
            }
            $mapping->update([
                'external_id' => $saved->getId(),
                'metadata' => ['calendar_connection_id' => $connection->id, 'calendar_id' => $connection->calendar_id],
            ]);
        } else {
            $saved = $service->events->insert($connection->calendar_id, $event);
            $mapping = IntegrationMapping::create([
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

        $normalized = app(GoogleCalendarSync::class)->normalizeEvent($saved, $connection);
        ExternalCalendarEvent::withTrashed()->updateOrCreate(
            [
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
    }

    public function delete(Meeting $meeting): void
    {
        $this->deleteByIdentifiers($meeting->campaign_id, $meeting->id);
    }

    public function deleteByIdentifiers(int $campaignId, int $meetingId): void
    {
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

        if (! $connection?->isReady() || ! $mapping) {
            return;
        }

        try {
            $this->factory->service($connection)->events->delete($connection->calendar_id, $mapping->external_id);
        } catch (\Google\Service\Exception $exception) {
            if ($exception->getCode() !== 404 && $exception->getCode() !== 410) {
                throw $exception;
            }
        }

        ExternalCalendarEvent::where('calendar_connection_id', $connection->id)
            ->where('external_event_id', $mapping->external_id)
            ->delete();
        $mapping->delete();
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
