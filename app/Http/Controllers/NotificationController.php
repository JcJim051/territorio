<?php

namespace App\Http\Controllers;

use App\Support\CurrentCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request, CurrentCampaign $current): Response
    {
        $campaignId = $current->campaign->id;
        $status = $request->string('status')->toString();

        $notifications = $request->user()
            ->notifications()
            ->where('data->campaign_id', $campaignId)
            ->when($status === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate(20)
            ->through(fn (DatabaseNotification $notification) => $this->serialize($notification));

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'filters' => ['status' => $status ?: 'all'],
        ]);
    }

    public function markRead(Request $request, string $notificationId, CurrentCampaign $current): RedirectResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($notificationId)
            ->where('data->campaign_id', $current->campaign->id)
            ->firstOrFail();

        $notification->markAsRead();

        return back();
    }

    public function markAllRead(Request $request, CurrentCampaign $current): RedirectResponse
    {
        $request->user()
            ->unreadNotifications()
            ->where('data->campaign_id', $current->campaign->id)
            ->update(['read_at' => now()]);

        return back()->with('success', 'Las notificaciones quedaron marcadas como leídas.');
    }

    private function serialize(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->data['title'] ?? 'Notificación',
            'message' => $notification->data['message'] ?? '',
            'href' => $notification->data['href'] ?? '/',
            'category' => $notification->data['category'] ?? 'general',
            'readAt' => $notification->read_at?->toIso8601String(),
            'createdAt' => $notification->created_at?->toIso8601String(),
        ];
    }
}
