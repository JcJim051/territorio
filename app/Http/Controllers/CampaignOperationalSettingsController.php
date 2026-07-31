<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\CurrentCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignOperationalSettingsController extends Controller
{
    public function edit(CurrentCampaign $current): Response
    {
        $current->authorize('campaign.settings.manage');

        return Inertia::render('Campaign/OperationalSettings', [
            'driverAgendaDays' => (int) ($current->campaign->settings['driver_agenda_days'] ?? 7),
        ]);
    }

    public function update(Request $request, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('campaign.settings.manage');
        $data = $request->validate(['driver_agenda_days' => ['required', 'integer', 'min:1', 'max:30']]);
        $settings = $current->campaign->settings ?? [];
        $old = ['driver_agenda_days' => (int) ($settings['driver_agenda_days'] ?? 7)];
        $settings['driver_agenda_days'] = (int) $data['driver_agenda_days'];
        $current->campaign->update(['settings' => $settings]);
        Audit::record('campaign.operational_settings_updated', $current->campaign, [
            'driver_agenda_days' => $settings['driver_agenda_days'],
        ], $old, $current->campaign);

        return back()->with('success', 'La configuración operativa fue actualizada.');
    }
}
