<?php

namespace App\Http\Controllers;

use App\Jobs\ApplyCalendarReviewRejection;
use App\Models\CalendarChangeReview;
use App\Models\ExternalCalendarEvent;
use App\Services\CalendarConflictService;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CalendarReviewController extends Controller
{
    public function index(Request $request, CurrentCampaign $current, CalendarConflictService $conflicts): Response
    {
        $current->authorize('calendar.changes.review');
        $status = $request->string('status', 'pending')->toString();
        $focusedReview = $request->string('review')->toString();
        $reviews = CalendarChangeReview::query()
            ->with(['event', 'meeting:id,public_id,title,starts_at,ends_at,address,latitude,longitude', 'connection:id,calendar_name,account_email'])
            ->where('campaign_id', $current->campaign->id)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($focusedReview, fn ($query) => $query->where('public_id', $focusedReview))
            ->latest()
            ->paginate(30)
            ->through(function (CalendarChangeReview $review) use ($conflicts) {
                $event = $review->event;
                $eventConflicts = $event?->starts_at && $event?->ends_at
                    ? $conflicts->find($review->campaign_id, $event->starts_at, $event->ends_at, $review->meeting_id, $event->id)
                    : collect();

                return [
                    'id' => $review->public_id,
                    'type' => $review->change_type,
                    'status' => $review->status,
                    'before' => $review->before_payload,
                    'after' => $review->after_payload,
                    'createdAt' => $review->created_at->toIso8601String(),
                    'reviewedAt' => $review->reviewed_at?->toIso8601String(),
                    'notes' => $review->review_notes,
                    'calendar' => $review->connection?->calendar_name,
                    'account' => $review->connection?->account_email,
                    'event' => $event ? [
                        'title' => $event->title,
                        'location' => $event->location,
                        'startsAt' => $event->starts_at?->toIso8601String(),
                        'endsAt' => $event->ends_at?->toIso8601String(),
                        'allDay' => $event->all_day,
                        'isBusy' => $event->is_busy,
                        'htmlLink' => $event->html_link,
                        'origin' => $event->origin,
                    ] : null,
                    'meeting' => $review->meeting ? [
                        'id' => $review->meeting->public_id,
                        'title' => $review->meeting->title,
                        'startsAt' => $review->meeting->starts_at?->toIso8601String(),
                        'endsAt' => $review->meeting->ends_at?->toIso8601String(),
                        'address' => $review->meeting->address,
                        'latitude' => $review->meeting->latitude,
                        'longitude' => $review->meeting->longitude,
                    ] : null,
                    'conflicts' => $eventConflicts->map(fn ($conflict) => [
                        ...$conflict,
                        'starts_at' => $conflict['starts_at']->toIso8601String(),
                        'ends_at' => $conflict['ends_at']->toIso8601String(),
                    ]),
                ];
            });

        return Inertia::render('Calendar/Reviews', [
            'reviews' => $reviews,
            'filters' => ['status' => $status, 'review' => $focusedReview ?: null],
            'summary' => [
                'pending' => CalendarChangeReview::where('campaign_id', $current->campaign->id)->where('status', 'pending')->count(),
                'approved' => CalendarChangeReview::where('campaign_id', $current->campaign->id)->where('status', 'approved')->count(),
                'rejected' => CalendarChangeReview::where('campaign_id', $current->campaign->id)->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function approve(Request $request, string $publicId, CurrentCampaign $current, CalendarConflictService $conflicts): RedirectResponse
    {
        $current->authorize('calendar.changes.review');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);
        $review = $this->findPending($publicId, $current);
        $event = $review->event;
        abort_unless($event, 422, 'El evento externo ya no se encuentra disponible.');

        if ($review->change_type !== 'deleted' && $event->is_busy && $event->starts_at && $event->ends_at) {
            $blocking = $conflicts->find($review->campaign_id, $event->starts_at, $event->ends_at, $review->meeting_id, $event->id);
            if ($blocking->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'calendar' => 'No se puede aprobar mientras exista el cruce con «'.$blocking->first()['title'].'».',
                ]);
            }
        }

        DB::transaction(function () use ($review, $event, $request, $data, $current) {
            if ($review->change_type === 'deleted') {
                if ($review->meeting) {
                    $review->meeting->update(['status' => 'cancelled']);
                }
                $event->delete();
            } elseif ($review->meeting && $review->change_type === 'updated') {
                $review->meeting->update([
                    'title' => $event->title,
                    'starts_at' => $event->starts_at,
                    'ends_at' => $event->ends_at,
                    'location' => $event->location ?: $review->meeting->location,
                    'address' => $event->location ?: $review->meeting->address,
                ]);
                $event->update(['review_status' => 'approved']);
            } else {
                $event->update(['review_status' => 'approved']);
            }
            $review->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['notes'] ?? null,
            ]);
            Audit::record('calendar.change_approved', $review, ['status' => 'approved'], campaign: $current->campaign);
        });

        return back()->with('success', 'El cambio de Google Calendar fue autorizado.');
    }

    public function reject(Request $request, string $publicId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('calendar.changes.review');
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        $review = $this->findPending($publicId, $current);
        DB::transaction(function () use ($review, $request, $data, $current) {
            $review->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['notes'],
            ]);
            $review->event?->update(['review_status' => 'rejection_pending']);
            Audit::record('calendar.change_rejected', $review, ['status' => 'rejected'], campaign: $current->campaign);
        });
        ApplyCalendarReviewRejection::dispatch($review->campaign_id, $review->id);

        return back()->with('success', 'Rechazo registrado. Google Calendar será restaurado y el bloqueo se liberará al confirmarse.');
    }

    private function findPending(string $publicId, CurrentCampaign $current): CalendarChangeReview
    {
        return CalendarChangeReview::with(['event', 'meeting', 'connection'])
            ->where('campaign_id', $current->campaign->id)
            ->where('public_id', $publicId)
            ->where('status', 'pending')
            ->firstOrFail();
    }
}
