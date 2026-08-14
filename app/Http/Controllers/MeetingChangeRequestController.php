<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCalendarOutbox;
use App\Models\MeetingChangeRequest;
use App\Services\CalendarConflictService;
use App\Services\CalendarOutbox;
use App\Services\MeetingResourceService;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeetingChangeRequestController extends Controller
{
    public function approve(
        Request $request,
        string $publicId,
        CurrentCampaign $current,
        CalendarConflictService $conflicts,
        CalendarOutbox $outbox,
        MeetingResourceService $meetingResources,
    ): RedirectResponse {
        $current->authorize('meetings.approve');
        $change = $this->find($publicId, $current);
        $proposed = $change->proposed_changes;
        $blocking = $conflicts->find(
            $current->campaign->id,
            Carbon::parse($proposed['starts_at']),
            Carbon::parse($proposed['ends_at']),
            $change->meeting_id,
        );
        if ($blocking->isNotEmpty()) {
            throw ValidationException::withMessages([
                'meeting_change' => 'No se puede aprobar: se cruza con «'.$blocking->first()['title'].'».',
            ]);
        }
        $meetingResources->assertAvailableForPeriod(
            $change->meeting,
            Carbon::parse($proposed['starts_at']),
            Carbon::parse($proposed['ends_at']),
        );

        DB::transaction(function () use ($change, $request, $outbox, $current, $meetingResources) {
            $change->meeting->update($change->proposed_changes);
            $meetingResources->rescheduleReservations($change->meeting->fresh());
            $change->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $request->string('notes')->toString() ?: null,
            ]);
            $outbox->meetingUpsert($change->meeting->fresh());
            Audit::record('meeting.change_approved', $change, ['status' => 'approved'], campaign: $current->campaign);
        });
        ProcessCalendarOutbox::dispatch((int) $change->campaign_id);

        return back()->with('success', 'El nuevo horario fue aprobado y se actualizará en Google Calendar.');
    }

    public function reject(Request $request, string $publicId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('meetings.approve');
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        $change = $this->find($publicId, $current);
        $change->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['notes'],
        ]);
        Audit::record('meeting.change_rejected', $change, ['status' => 'rejected'], campaign: $current->campaign);

        return back()->with('success', 'El cambio fue rechazado; se conserva el horario vigente.');
    }

    private function find(string $publicId, CurrentCampaign $current): MeetingChangeRequest
    {
        return MeetingChangeRequest::with('meeting')
            ->where('campaign_id', $current->campaign->id)
            ->where('public_id', $publicId)
            ->where('status', 'pending')
            ->firstOrFail();
    }
}
