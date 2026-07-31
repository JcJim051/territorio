<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'minimum_quantity' => 'float',
            'is_shared' => 'boolean',
        ];
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
