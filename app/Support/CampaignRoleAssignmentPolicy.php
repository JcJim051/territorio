<?php

namespace App\Support;

use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use Illuminate\Validation\ValidationException;

class CampaignRoleAssignmentPolicy
{
    public function canAssign(CampaignMembership $actor, CampaignRole $role): bool
    {
        if ($actor->user?->is_super_admin) {
            return true;
        }

        return $role->campaign_id === $actor->campaign_id
            && $role->assignment_level < ($actor->role?->assignment_level ?? 0);
    }

    public function canManage(CampaignMembership $actor, CampaignMembership $target): bool
    {
        if ($actor->user?->is_super_admin) {
            return true;
        }

        return ! $target->user?->is_super_admin
            && $target->campaign_id === $actor->campaign_id
            && ($target->role?->assignment_level ?? 0) < ($actor->role?->assignment_level ?? 0);
    }

    public function authorizeAssignment(CampaignMembership $actor, CampaignRole $role): void
    {
        if (! $this->canAssign($actor, $role)) {
            throw ValidationException::withMessages([
                'campaign_role_id' => 'No puedes asignar un rol de nivel igual o superior al tuyo.',
            ]);
        }
    }

    public function authorizeTarget(CampaignMembership $actor, CampaignMembership $target): void
    {
        if (! $this->canManage($actor, $target)) {
            abort(403, 'No puedes administrar usuarios de nivel igual o superior al tuyo.');
        }
    }
}
