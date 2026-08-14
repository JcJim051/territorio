<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicSiteSocialPost extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_on' => 'date',
            'featured' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CampaignPublicSite::class, 'campaign_public_site_id');
    }
}
