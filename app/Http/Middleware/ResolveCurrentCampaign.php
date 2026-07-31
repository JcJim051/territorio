<?php

namespace App\Http\Middleware;

use App\Models\CampaignMembership;
use App\Support\CurrentCampaign;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentCampaign
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->is_active === false) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Esta cuenta se encuentra inactiva.']);
        }

        $membershipQuery = CampaignMembership::query()
            ->with(['campaign.organization', 'role', 'user'])
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->whereHas('campaign', fn ($campaign) => $campaign->where('status', 'active'));

        $requestedId = $request->user()->is_super_admin
            ? ($request->integer('campaign') ?: (int) $request->session()->get('campaign_id'))
            : null;
        $membership = $requestedId
            ? (clone $membershipQuery)->where('campaign_id', $requestedId)->first()
            : null;
        $membership ??= $membershipQuery->orderBy('id')->first();

        abort_unless($membership, 403, 'No tienes una campaña activa asignada.');

        $request->session()->put('campaign_id', $membership->campaign_id);
        $membership->forceFill(['last_accessed_at' => now()])->saveQuietly();

        app()->instance(CurrentCampaign::class, new CurrentCampaign($membership->campaign, $membership));

        return $next($request);
    }
}
