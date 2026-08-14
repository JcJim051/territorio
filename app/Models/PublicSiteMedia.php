<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicSiteMedia extends Model
{
    protected $guarded = [];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CampaignPublicSite::class, 'campaign_public_site_id');
    }
}
