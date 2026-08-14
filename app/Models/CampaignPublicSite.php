<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignPublicSite extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'draft_content' => 'array',
            'published_content' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(PublicSiteMedia::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(PublicSiteSocialAccount::class);
    }

    public function socialPosts(): HasMany
    {
        return $this->hasMany(PublicSiteSocialPost::class);
    }
}
