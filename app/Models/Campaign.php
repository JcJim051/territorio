<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Campaign extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'election_at' => 'date',
            'enabled_modules' => 'array',
            'settings' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CampaignMembership::class);
    }

    public function persons(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function calendarConnections(): HasMany
    {
        return $this->hasMany(CalendarConnection::class);
    }

    public function serviceCredentials(): HasMany
    {
        return $this->hasMany(CampaignServiceCredential::class);
    }

    public function publicSite(): HasOne
    {
        return $this->hasOne(CampaignPublicSite::class);
    }
}
