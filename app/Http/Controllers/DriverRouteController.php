<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Support\CurrentCampaign;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class DriverRouteController extends Controller
{
    public function __invoke(CurrentCampaign $current): Response
    {
        $current->authorize('driver.routes.view');
        $days = max(1, min(30, (int) ($current->campaign->settings['driver_agenda_days'] ?? 7)));
        $timezone = $current->campaign->timezone ?: 'America/Bogota';
        $from = CarbonImmutable::now($timezone)->startOfDay();
        $until = $from->addDays($days)->endOfDay();

        $meetings = Meeting::query()
            ->where('campaign_id', $current->campaign->id)
            ->where('status', 'approved')
            ->whereBetween('starts_at', [$from->utc(), $until->utc()])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Meeting $meeting) use ($timezone) {
                $destination = $meeting->latitude !== null && $meeting->longitude !== null
                    ? $meeting->latitude.','.$meeting->longitude
                    : ($meeting->address ?: $meeting->location);

                return [
                    'id' => $meeting->public_id,
                    'date' => $meeting->starts_at->timezone($timezone)->format('Y-m-d'),
                    'startsAt' => $meeting->starts_at->timezone($timezone)->toIso8601String(),
                    'endsAt' => $meeting->ends_at->timezone($timezone)->toIso8601String(),
                    'place' => $meeting->location,
                    'address' => $meeting->address,
                    'directions' => $meeting->location_notes,
                    'googleMapsUrl' => 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode((string) $destination),
                    'wazeUrl' => $meeting->latitude !== null && $meeting->longitude !== null
                        ? 'https://waze.com/ul?ll='.rawurlencode($meeting->latitude.','.$meeting->longitude).'&navigate=yes'
                        : 'https://waze.com/ul?q='.rawurlencode((string) $destination).'&navigate=yes',
                ];
            });

        return Inertia::render('Driver/Routes', [
            'days' => $days,
            'dates' => $meetings->groupBy('date')->map->values(),
            'nextMeetingId' => $meetings->firstWhere('startsAt', '>=', now()->toIso8601String())['id'] ?? $meetings->first()['id'] ?? null,
        ]);
    }
}
