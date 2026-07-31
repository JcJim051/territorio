<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Election extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['election_at' => 'date'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(DivipolSnapshot::class);
    }
}
