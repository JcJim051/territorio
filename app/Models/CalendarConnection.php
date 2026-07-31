<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token', 'watch_token_hash'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted:array',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
            'last_synced_at' => 'datetime',
            'watch_expires_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExternalCalendarEvent::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(CalendarChangeReview::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'active' && filled($this->calendar_id) && filled($this->refresh_token);
    }
}
