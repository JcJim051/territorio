<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DivipolSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['cutoff_at' => 'date', 'is_active' => 'boolean', 'metadata' => 'array'];
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function votingPlaces(): HasMany
    {
        return $this->hasMany(VotingPlace::class);
    }
}
