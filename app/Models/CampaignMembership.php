<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignMembership extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'territorial_scope' => 'array',
            'is_active' => 'boolean',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(CampaignRole::class, 'campaign_role_id');
    }

    public function can(string $permission): bool
    {
        if ($this->user?->is_super_admin) {
            return true;
        }

        $permissions = $this->role?->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
