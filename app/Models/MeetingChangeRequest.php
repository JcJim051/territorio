<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingChangeRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'proposed_changes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
