<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncCursor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
