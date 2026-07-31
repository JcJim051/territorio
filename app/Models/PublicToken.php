<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicToken extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'territorial_scope' => 'array',
            'token_ciphertext' => 'encrypted',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'owner_person_id');
    }

    protected $hidden = ['token_hash', 'token_ciphertext'];

    public function isUsable(): bool
    {
        return ! $this->revoked_at
            && (! $this->expires_at || $this->expires_at->isFuture())
            && (! $this->max_uses || $this->uses < $this->max_uses);
    }
}
