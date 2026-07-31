<?php

namespace App\Services;

use App\Models\Person;
use App\Models\ReferralRelationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralNetworkService
{
    public function connect(Person $parent, Person $child, ?int $invitationId = null): ReferralRelationship
    {
        if ($parent->campaign_id !== $child->campaign_id) {
            throw ValidationException::withMessages(['network' => 'Los nodos deben pertenecer a la misma campaña.']);
        }
        if ($parent->is($child)) {
            throw ValidationException::withMessages(['network' => 'Una persona no puede referirse a sí misma.']);
        }

        return DB::transaction(function () use ($parent, $child, $invitationId) {
            $activeParent = ReferralRelationship::query()
                ->where('campaign_id', $child->campaign_id)
                ->where('child_person_id', $child->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->exists();

            if ($activeParent) {
                throw ValidationException::withMessages(['network' => 'La persona ya tiene un nodo superior activo.']);
            }
            if ($this->isDescendant($parent, $child)) {
                throw ValidationException::withMessages(['network' => 'La relación produciría un ciclo en la red.']);
            }

            $parentRelationship = ReferralRelationship::query()
                ->where('campaign_id', $parent->campaign_id)
                ->where('child_person_id', $parent->id)
                ->whereNull('ended_at')
                ->first();
            $parentPath = $parentRelationship?->path ?: (string) $parent->id;

            return ReferralRelationship::create([
                'campaign_id' => $parent->campaign_id,
                'parent_person_id' => $parent->id,
                'child_person_id' => $child->id,
                'referral_invitation_id' => $invitationId,
                'path' => $parentPath.'.'.$child->id,
                'depth' => substr_count($parentPath, '.') + 1,
                'started_at' => now(),
            ]);
        });
    }

    private function isDescendant(Person $candidateParent, Person $child): bool
    {
        $frontier = [$child->id];
        $visited = [];

        while ($frontier !== []) {
            $visited = array_values(array_unique([...$visited, ...$frontier]));
            $next = ReferralRelationship::query()
                ->where('campaign_id', $child->campaign_id)
                ->whereNull('ended_at')
                ->whereIn('parent_person_id', $frontier)
                ->pluck('child_person_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (in_array($candidateParent->id, $next, true)) {
                return true;
            }
            $frontier = array_values(array_diff($next, $visited));
        }

        return false;
    }
}
