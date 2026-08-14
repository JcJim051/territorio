<?php

namespace App\Support;

use App\Models\CampaignMembership;
use App\Models\User;
use App\Notifications\CampaignActivityNotification;
use Illuminate\Support\Collection;

class CampaignNotifier
{
    public function notifyPermission(
        int $campaignId,
        string $permission,
        CampaignActivityNotification $notification,
        ?int $exceptUserId = null,
    ): void {
        $this->recipients($campaignId, $permission, $exceptUserId)
            ->each(fn (User $user) => $user->notify($notification));
    }

    public function notifyPermissions(
        int $campaignId,
        array $permissions,
        CampaignActivityNotification $notification,
        ?int $exceptUserId = null,
    ): void {
        collect($permissions)
            ->flatMap(fn (string $permission) => $this->recipients($campaignId, $permission, $exceptUserId))
            ->unique('id')
            ->values()
            ->each(fn (User $user) => $user->notify($notification));
    }

    public function notifyUsers(Collection $users, CampaignActivityNotification $notification, ?int $exceptUserId = null): void
    {
        $users
            ->filter(fn (?User $user) => $user && $user->id !== $exceptUserId)
            ->unique('id')
            ->values()
            ->each(fn (User $user) => $user->notify($notification));
    }

    private function recipients(int $campaignId, string $permission, ?int $exceptUserId = null): Collection
    {
        return CampaignMembership::query()
            ->with(['user', 'role'])
            ->where('campaign_id', $campaignId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (CampaignMembership $membership) => $membership->user
                && $membership->user->is_active
                && $membership->user->id !== $exceptUserId
                && $membership->can($permission))
            ->map(fn (CampaignMembership $membership) => $membership->user)
            ->unique('id')
            ->values();
    }
}
