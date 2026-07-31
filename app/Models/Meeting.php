<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'approved_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'leader_person_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(TerritoryUnit::class, 'territory_unit_id');
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(ExternalCalendarEvent::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(MeetingChangeRequest::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(MeetingRequirement::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
