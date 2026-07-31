<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Person extends Model
{
    use SoftDeletes;

    protected $table = 'persons';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'document_number' => 'encrypted',
            'verified_at' => 'datetime',
            'is_referral_node' => 'boolean',
            'promoted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function votingPlace(): BelongsTo
    {
        return $this->belongsTo(VotingPlace::class);
    }

    public function votingTable(): BelongsTo
    {
        return $this->belongsTo(VotingTable::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(ReferralRelationship::class, 'parent_person_id')->whereNull('ended_at');
    }

    public function parentRelationships(): HasMany
    {
        return $this->hasMany(ReferralRelationship::class, 'child_person_id')->whereNull('ended_at');
    }

    public function publicTokens(): HasMany
    {
        return $this->hasMany(PublicToken::class, 'owner_person_id');
    }
}
