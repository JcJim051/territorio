<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralRelationship extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'parent_person_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'child_person_id');
    }
}
