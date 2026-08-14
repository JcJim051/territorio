<?php

namespace App\Support;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Support\Tenancy\AuthorizedExecutionContext;
use App\Support\Tenancy\UnauthorizedExecutionContext;
use Illuminate\Validation\ValidationException;

class CurrentCampaign
{
    public function __construct(
        public readonly Campaign $campaign,
        public readonly CampaignMembership $membership,
        public readonly AuthorizedExecutionContext $executionContext,
    ) {
        if (
            $executionContext->campaignId() !== (int) $campaign->id
            || (int) $membership->campaign_id !== (int) $campaign->id
        ) {
            throw new UnauthorizedExecutionContext('CurrentCampaign requiere una resolución autorizada coincidente.');
        }
    }

    public function authorize(string $permission): void
    {
        abort_unless($this->membership->can($permission), 403);
    }

    public function territoryIds(): array
    {
        return collect($this->membership->territorial_scope['territory_unit_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function hasGlobalTerritorialScope(): bool
    {
        return $this->territoryIds() === [];
    }

    public function authorizeTerritory(?int $territoryId): void
    {
        if ($this->hasGlobalTerritorialScope()) {
            return;
        }
        if (! $territoryId || ! in_array($territoryId, $this->territoryIds(), true)) {
            throw ValidationException::withMessages([
                'territory' => 'El territorio está fuera del alcance asignado a tu usuario.',
            ]);
        }
    }
}
