<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarChangeReview extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_payload' => 'array',
            'after_payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ExternalCalendarEvent::class, 'external_calendar_event_id')->withTrashed();
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
