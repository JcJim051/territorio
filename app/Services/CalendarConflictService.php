<?php

namespace App\Services;

use App\Models\ExternalCalendarEvent;
use App\Models\Meeting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class CalendarConflictService
{
    public function find(
        int $campaignId,
        CarbonInterface|string $startsAt,
        CarbonInterface|string $endsAt,
        ?int $excludeMeetingId = null,
        ?int $excludeExternalEventId = null,
    ): Collection {
        $meetings = Meeting::query()
            ->where('campaign_id', $campaignId)
            ->when($excludeMeetingId, fn ($query) => $query->whereKeyNot($excludeMeetingId))
            ->whereIn('status', ['approved', 'conditional'])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->get()
            ->map(fn (Meeting $meeting) => [
                'kind' => 'meeting',
                'id' => $meeting->public_id,
                'title' => $meeting->title,
                'starts_at' => $meeting->starts_at,
                'ends_at' => $meeting->ends_at,
                'location' => $meeting->address ?: $meeting->location,
            ]);

        $external = ExternalCalendarEvent::query()
            ->where('campaign_id', $campaignId)
            ->when($excludeExternalEventId, fn ($query) => $query->whereKeyNot($excludeExternalEventId))
            ->when($excludeMeetingId, fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('meeting_id')
                ->orWhere('meeting_id', '!=', $excludeMeetingId)))
            ->where('is_busy', true)
            ->whereIn('review_status', ['pending', 'approved', 'rejection_pending'])
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->get()
            ->map(fn (ExternalCalendarEvent $event) => [
                'kind' => 'google',
                'id' => (string) $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at,
                'ends_at' => $event->ends_at,
                'location' => $event->location,
            ]);

        return $meetings->concat($external)->sortBy('starts_at')->values();
    }
}
