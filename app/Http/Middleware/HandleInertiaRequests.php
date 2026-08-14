<?php

namespace App\Http\Middleware;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Support\CurrentCampaign;
use App\Support\Tenancy\CampaignContextResolver;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $current = app()->bound(CurrentCampaign::class) ? app(CurrentCampaign::class) : null;
        if (! $current && $request->user()) {
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
            $membership ??= $membershipQuery->orderBy('id')->first();

            if ($membership) {
                $context = app(CampaignContextResolver::class)->fromMembership($membership);
                $current = new CurrentCampaign($membership->campaign, $membership, $context);
            }
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
            'notifications' => fn () => ! $request->user() || ! $current
                ? ['unread' => 0, 'latest' => []]
                : [
                    'unread' => $request->user()
                        ->unreadNotifications()
                        ->where('data->campaign_id', $current->campaign->id)
                        ->count(),
                    'latest' => $request->user()
                        ->notifications()
                        ->where('data->campaign_id', $current->campaign->id)
                        ->latest()
                        ->limit(6)
                        ->get()
                        ->map(fn ($notification) => [
                            'id' => $notification->id,
                            'title' => $notification->data['title'] ?? 'Notificación',
                            'message' => $notification->data['message'] ?? '',
                            'href' => $notification->data['href'] ?? '/',
                            'category' => $notification->data['category'] ?? 'general',
                            'readAt' => $notification->read_at?->toIso8601String(),
                            'createdAt' => $notification->created_at?->toIso8601String(),
                        ]),
                ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
