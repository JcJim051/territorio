<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\Person;
use App\Models\ReferralRelationship;
use App\Models\Resource;
use App\Models\TerritoryUnit;
use App\Models\CalendarChangeReview;
use App\Support\CurrentCampaign;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(CurrentCampaign $current): Response
    {
        $current->authorize('dashboard.view');
        $campaign = $current->campaign;
        $peopleQuery = Person::where('campaign_id', $campaign->id)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                'votingPlace',
                fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
            ));
        $meetingQuery = Meeting::where('campaign_id', $campaign->id)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('territory_unit_id', $current->territoryIds()));
        $visiblePersonIds = (clone $peopleQuery)->select('id');
        $peopleCount = (clone $peopleQuery)->count();
        $verifiedCount = (clone $peopleQuery)->where('status', 'verified')->count();
        $relationshipsCount = ReferralRelationship::where('campaign_id', $campaign->id)
            ->whereNull('ended_at')
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query
                ->whereIn('parent_person_id', clone $visiblePersonIds)
                ->whereIn('child_person_id', clone $visiblePersonIds))
            ->count();
        $pendingMeetings = (clone $meetingQuery)->where('status', 'requested')->count();
        $pendingPeople = (clone $peopleQuery)->where('status', 'pending')->count();
        $inventoryAlerts = Resource::query()
            ->where('organization_id', $campaign->organization_id)
            ->where(fn ($query) => $query->whereNull('campaign_id')->orWhere('campaign_id', $campaign->id))
            ->whereColumn('quantity', '<=', 'minimum_quantity')
            ->count();
        $calendarChanges = CalendarChangeReview::where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->count();

        $upcoming = Meeting::query()
            ->with(['leader:id,name', 'territory:id,name'])
            ->where('campaign_id', $campaign->id)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('territory_unit_id', $current->territoryIds()))
            ->where('starts_at', '>=', now()->startOfDay())
            ->orderBy('starts_at')
            ->limit(6)
            ->get()
            ->map(fn (Meeting $meeting) => [
                'id' => $meeting->public_id,
                'title' => $meeting->title,
                'type' => $meeting->type,
                'status' => $meeting->status,
                'startsAt' => $meeting->starts_at->toIso8601String(),
                'location' => $meeting->location,
                'expectedAttendees' => $meeting->expected_attendees,
                'leader' => $meeting->leader?->name,
                'territory' => $meeting->territory?->name,
            ]);

        $territories = Person::query()
            ->leftJoin('voting_places', 'voting_places.id', '=', 'persons.voting_place_id')
            ->where('persons.campaign_id', $campaign->id)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('voting_places.territory_unit_id', $current->territoryIds()))
            ->selectRaw("COALESCE(voting_places.commune, 'Sin ubicación') as label, COUNT(*) as total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $recentGrowth = Person::query()
            ->where('campaign_id', $campaign->id)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                'votingPlace',
                fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
            ))
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return Inertia::render('Dashboard', [
            'metrics' => [
                'people' => $peopleCount,
                'verified' => $verifiedCount,
                'networkLinks' => $relationshipsCount,
                'pendingMeetings' => $pendingMeetings,
                'coverage' => TerritoryUnit::where('campaign_id', $campaign->id)
                    ->where('type', 'commune')
                    ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('id', $current->territoryIds()))
                    ->count(),
                'inventoryAlerts' => $inventoryAlerts,
            ],
            'upcomingMeetings' => $upcoming,
            'territories' => $territories,
            'growth' => $recentGrowth,
            'capabilities' => [
                'territorial' => $current->membership->can('territorial.view'),
                'meetings' => $current->membership->can('meetings.view'),
                'inventory' => $current->membership->can('inventory.view'),
            ],
            'decisionQueue' => [
                ...($current->membership->can('meetings.approve') ? [['type' => 'meeting', 'count' => $pendingMeetings, 'label' => 'Reuniones por aprobar', 'href' => '/meetings?status=requested', 'action' => 'Gestionar agenda']] : []),
                ...($current->membership->can('territorial.verify') ? [['type' => 'people', 'count' => $pendingPeople, 'label' => 'Personas por verificar', 'href' => '/people?status=pending', 'action' => 'Verificar personas']] : []),
                ...($current->membership->can('inventory.manage') ? [['type' => 'inventory', 'count' => $inventoryAlerts, 'label' => 'Faltantes por resolver', 'href' => '/inventory?alert=low', 'action' => 'Resolver faltantes']] : []),
                ...($current->membership->can('calendar.changes.review') ? [[
                    'type' => 'calendar',
                    'count' => $calendarChanges,
                    'label' => 'Cambios de Google Calendar',
                    'href' => '/calendar/reviews?status=pending',
                    'action' => 'Autorizar cambios',
                ]] : []),
            ],
        ]);
    }
}
