<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CampaignSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);
        $data = $request->validate(['campaign_id' => ['required', 'integer']]);
        $campaign = Campaign::whereKey($data['campaign_id'])->where('status', 'active')->firstOrFail();
        CampaignMembership::firstOrCreate(
            ['user_id' => $request->user()->id, 'campaign_id' => $campaign->id],
            ['campaign_role_id' => null, 'is_active' => true],
        );
        CampaignMembership::where('user_id', $request->user()->id)
            ->where('campaign_id', $campaign->id)
            ->update(['is_active' => true, 'last_accessed_at' => now()]);
        $request->session()->put('campaign_id', $data['campaign_id']);

        return back()->with('success', 'Ahora estás gestionando la campaña de '.$campaign->candidate_name.'.');
    }
}
