<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
