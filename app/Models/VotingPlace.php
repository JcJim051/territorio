<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VotingPlace extends Model
{
    protected $guarded = [];

    public function territoryUnit(): BelongsTo
    {
        return $this->belongsTo(TerritoryUnit::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(VotingTable::class);
    }
}
