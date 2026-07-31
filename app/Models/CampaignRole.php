<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRole extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean', 'assignment_level' => 'integer'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CampaignMembership::class, 'campaign_role_id');
    }
}
