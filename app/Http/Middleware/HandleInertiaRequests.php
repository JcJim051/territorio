<?php

namespace App\Http\Middleware;

use App\Models\CampaignMembership;
use App\Models\Campaign;
use App\Support\CurrentCampaign;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $current = null;
        if ($request->user()) {
            $membershipQuery = CampaignMembership::query()
                ->with(['campaign.organization', 'role', 'user'])
                ->where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->whereHas('campaign', fn ($campaign) => $campaign->where('status', 'active'));
            $membership = $request->user()->is_super_admin
                ? (clone $membershipQuery)
                    ->where('campaign_id', (int) $request->session()->get('campaign_id'))
                    ->first()
                : null;
            $membership ??= CampaignMembership::query()
                ->with(['campaign.organization', 'role', 'user'])
                ->where('user_id', $request->user()->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
            $current = $membership ? new CurrentCampaign($membership->campaign, $membership) : null;
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email'),
            ],
            'currentCampaign' => $current ? [
                'id' => $current->campaign->id,
                'name' => $current->campaign->name,
                'candidateName' => $current->campaign->candidate_name,
                'office' => $current->campaign->office,
                'territory' => $current->campaign->territory,
                'electionAt' => $current->campaign->election_at?->format('Y-m-d'),
                'themeColor' => $current->campaign->theme_color,
                'role' => $current->membership->user?->is_super_admin
                    ? 'Superadministrador global'
                    : $current->membership->role?->name,
                'permissions' => $current->membership->user?->is_super_admin
                    ? ['*']
                    : ($current->membership->role?->permissions ?? []),
                'isSuperAdmin' => (bool) $current->membership->user?->is_super_admin,
            ] : null,
            'campaigns' => fn () => ! $request->user()
                ? []
                : ($request->user()->is_super_admin
                    ? Campaign::query()
                        ->where('status', 'active')
                        ->orderBy('election_at')
                        ->orderBy('candidate_name')
                        ->get(['id', 'name', 'candidate_name', 'office', 'territory', 'election_at', 'theme_color'])
                    : collect([$current?->campaign])->filter())
                    ->map(fn (Campaign $campaign) => [
                        'id' => $campaign->id,
                        'name' => $campaign->name,
                        'candidateName' => $campaign->candidate_name,
                        'office' => $campaign->office,
                        'territory' => $campaign->territory,
                        'electionAt' => $campaign->election_at?->format('Y-m-d'),
                        'themeColor' => $campaign->theme_color,
                    ])
                    ->values(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
