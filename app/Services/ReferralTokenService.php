<?php

namespace App\Services;

use App\Models\Person;
use App\Models\PublicToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReferralTokenService
{
    public function promote(Person $person, User $actor, array $configuration): array
    {
        return DB::transaction(function () use ($person, $actor, $configuration) {
            $locked = Person::whereKey($person->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['verified', 'active'], true)) {
                throw ValidationException::withMessages([
                    'person' => 'Solo una persona verificada o activa puede convertirse en nodo.',
                ]);
            }
            if ($this->activeTokenQuery($locked)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['person' => 'Este nodo ya tiene un enlace activo.']);
            }

            $locked->update([
                'is_referral_node' => true,
                'promoted_by' => $actor->id,
                'promoted_at' => $locked->promoted_at ?? now(),
            ]);

            return $this->issue($locked, $actor, $configuration);
        });
    }

    public function rotate(Person $person, User $actor, array $configuration): array
    {
        return DB::transaction(function () use ($person, $actor, $configuration) {
            $locked = Person::whereKey($person->id)->lockForUpdate()->firstOrFail();
            $this->activeTokenQuery($locked)->lockForUpdate()->update(['revoked_at' => now()]);
            if (! $locked->is_referral_node) {
                $locked->update(['is_referral_node' => true, 'promoted_by' => $actor->id, 'promoted_at' => now()]);
            }

            return $this->issue($locked, $actor, $configuration);
        });
    }

    public function demote(Person $person): void
    {
        DB::transaction(function () use ($person) {
            $locked = Person::whereKey($person->id)->lockForUpdate()->firstOrFail();
            if ($locked->children()->exists()) {
                throw ValidationException::withMessages([
                    'person' => 'No se puede retirar como nodo porque ya tiene referidos directos. Revoca solamente su enlace si no debe continuar captando.',
                ]);
            }
            $this->activeTokenQuery($locked)->lockForUpdate()->update(['revoked_at' => now()]);
            $locked->update(['is_referral_node' => false, 'promoted_by' => null, 'promoted_at' => null]);
        });
    }

    private function issue(Person $person, User $actor, array $configuration): array
    {
        $plainToken = Str::random(64);
        $token = PublicToken::create([
            'campaign_id' => $person->campaign_id,
            'owner_person_id' => $person->id,
            'created_by' => $actor->id,
            'token_hash' => hash('sha256', $plainToken),
            'token_ciphertext' => $plainToken,
            'label' => $configuration['label'] ?: 'Referidos de '.$person->name,
            'abilities' => ['referrals.create'],
            'territorial_scope' => $configuration['territory_unit_ids']
                ? ['territory_unit_ids' => $configuration['territory_unit_ids']]
                : null,
            'max_uses' => $configuration['max_uses'] ?: null,
            'expires_at' => $configuration['expires_at'] ?: null,
        ]);

        return [$token, $plainToken];
    }

    private function activeTokenQuery(Person $person)
    {
        return PublicToken::where('campaign_id', $person->campaign_id)
            ->where('owner_person_id', $person->id)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }
}
