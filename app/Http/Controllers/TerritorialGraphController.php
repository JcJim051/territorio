<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\ReferralRelationship;
use App\Support\CurrentCampaign;
use Inertia\Inertia;
use Inertia\Response;

class TerritorialGraphController extends Controller
{
    private const MAX_VISIBLE_NODES = 500;

    public function __invoke(CurrentCampaign $current): Response
    {
        $current->authorize('territorial.view');
        $campaignId = $current->campaign->id;

        $people = Person::query()
            ->where('campaign_id', $campaignId)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                'votingPlace',
                fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
            ))
            ->withCount('children')
            ->orderByDesc('children_count')
            ->limit(self::MAX_VISIBLE_NODES)
            ->get(['id', 'public_id', 'name', 'status', 'voting_place_id']);

        $visibleIds = $people->pluck('id');
        $relationships = ReferralRelationship::query()
            ->where('campaign_id', $campaignId)
            ->whereNull('ended_at')
            ->whereIn('parent_person_id', $visibleIds)
            ->whereIn('child_person_id', $visibleIds)
            ->get(['parent_person_id', 'child_person_id']);

        return Inertia::render('Territorial/Graph', [
            'nodes' => $people->map(fn (Person $person) => [
                'id' => (string) $person->id,
                'publicId' => $person->public_id,
                'name' => $person->name,
                'status' => $person->status,
                'children' => $person->children_count,
            ]),
            'edges' => $relationships->map(fn (ReferralRelationship $relationship) => [
                'source' => (string) $relationship->parent_person_id,
                'target' => (string) $relationship->child_person_id,
            ]),
            'truncated' => Person::where('campaign_id', $campaignId)
                ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                    'votingPlace',
                    fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
                ))
                ->count() > self::MAX_VISIBLE_NODES,
        ]);
    }
}
