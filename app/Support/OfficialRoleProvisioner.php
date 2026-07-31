<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\CampaignRole;
use Illuminate\Support\Facades\DB;

class OfficialRoleProvisioner
{
    public function provision(Campaign $campaign): array
    {
        return DB::transaction(function () use ($campaign) {
            $definitions = OfficialRoleCatalog::definitions();
            $technical = CampaignRole::where('campaign_id', $campaign->id)
                ->where(fn ($query) => $query
                    ->whereIn('slug', ['administrator', 'administration', 'gerencia'])
                    ->orWhereJsonContains('permissions', '*'))
                ->orderBy('id')
                ->first();

            if ($technical && ! CampaignRole::where('campaign_id', $campaign->id)->where('slug', 'technical-administrator')->exists()) {
                $definition = $definitions['technical-administrator'];
                $technical->update([
                    'name' => $definition['name'],
                    'slug' => 'technical-administrator',
                    'permissions' => $definition['permissions'],
                    'assignment_level' => $definition['level'],
                    'is_system' => true,
                ]);
            }

            $roles = [];
            foreach ($definitions as $slug => $definition) {
                $roles[$slug] = CampaignRole::updateOrCreate(
                    ['campaign_id' => $campaign->id, 'slug' => $slug],
                    [
                        'name' => $definition['name'],
                        'permissions' => $definition['permissions'],
                        'assignment_level' => $definition['level'],
                        'is_system' => true,
                    ],
                );
            }

            return $roles;
        });
    }
}
