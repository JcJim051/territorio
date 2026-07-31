<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\OutboxEvent;
use Illuminate\Support\Str;

class CalendarOutbox
{
    public function meetingUpsert(Meeting $meeting): OutboxEvent
    {
        return $this->record('calendar.meeting.upsert', $meeting);
    }

    public function meetingDelete(Meeting $meeting): OutboxEvent
    {
        return $this->record('calendar.meeting.delete', $meeting);
    }

    private function record(string $type, Meeting $meeting): OutboxEvent
    {
        return OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'campaign_id' => $meeting->campaign_id,
            'type' => $type,
            'aggregate_type' => Meeting::class,
            'aggregate_id' => (string) $meeting->id,
            'payload' => [
                'meeting_public_id' => $meeting->public_id,
                'meeting_id' => $meeting->id,
                'campaign_id' => $meeting->campaign_id,
                'google_event_id' => $meeting->google_event_id,
                'version' => $meeting->updated_at?->format('Y-m-d\TH:i:s.uP'),
            ],
            'occurred_at' => now(),
        ]);
    }
}
