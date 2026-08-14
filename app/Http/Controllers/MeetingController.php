<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCalendarOutbox;
use App\Models\CalendarConnection;
use App\Models\ExternalCalendarEvent;
use App\Models\IntegrationMapping;
use App\Models\Meeting;
use App\Models\MeetingChangeRequest;
use App\Models\OutboxEvent;
use App\Models\Person;
use App\Models\TerritoryUnit;
use App\Notifications\CampaignActivityNotification;
use App\Services\CalendarConflictService;
use App\Services\CalendarOutbox;
use App\Services\MeetingResourceService;
use App\Support\Audit;
use App\Support\CampaignNotifier;
use App\Support\CurrentCampaign;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    public function index(Request $request, CurrentCampaign $current, MeetingResourceService $meetingResources): Response
    {
        $current->authorize('meetings.view');
        $campaignId = $current->campaign->id;
        $status = $request->string('status')->toString();
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $selectedDate = $request->string('date')->toString();
        $month = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', $request->string('month')->toString())->startOfMonth()
            : now()->startOfMonth();
        $rangeStart = $month->copy()->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $rangeEnd = $month->copy()->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY)->addDay()->startOfDay();
        $calendarMeetings = Meeting::query()
            ->with([
                'leader:id,name',
                'territory:id,name',
                'requirements.resource',
                'calendarEvents' => fn ($query) => $query
                    ->where('campaign_id', $campaignId)
                    ->where('origin', 'platform')
                    ->latest('id'),
                'changeRequests' => fn ($query) => $query->where('status', 'pending')->latest(),
            ])
            ->where('campaign_id', $campaignId)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('territory_unit_id', $current->territoryIds()))
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->orderBy('starts_at')
            ->get();
        $externalEvents = ExternalCalendarEvent::query()
            ->with(['reviews' => fn ($query) => $query->where('status', 'pending')->latest()])
            ->where('campaign_id', $campaignId)
            ->whereIn('review_status', ['pending', 'approved', 'rejection_pending'])
            ->where(fn ($query) => $query
                ->where('origin', 'google')
                ->orWhere('review_status', '!=', 'approved'))
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->orderBy('starts_at')
            ->get();
        $connection = CalendarConnection::query()
            ->where('campaign_id', $campaignId)
            ->first();
        $latestOutbox = OutboxEvent::query()
            ->where('campaign_id', $campaignId)
            ->where('aggregate_type', Meeting::class)
            ->whereIn('aggregate_id', $calendarMeetings->pluck('id')->map(fn ($id) => (string) $id))
            ->where('type', 'calendar.meeting.upsert')
            ->latest('id')
            ->get()
            ->unique('aggregate_id')
            ->keyBy('aggregate_id');
        $calendarMappings = IntegrationMapping::query()
            ->where('campaign_id', $campaignId)
            ->where('system', 'google_calendar')
            ->where('entity_type', 'meeting')
            ->whereIn('local_id', $calendarMeetings->pluck('id')->map(fn ($id) => (string) $id))
            ->get()
            ->keyBy('local_id');

        return Inertia::render('Meetings/Index', [
            'meetings' => $calendarMeetings
                ->map(function (Meeting $meeting) use ($calendarMeetings, $calendarMappings, $externalEvents, $connection, $latestOutbox, $meetingResources) {
                    $conflicts = $calendarMeetings
                        ->filter(fn (Meeting $other) => $other->id !== $meeting->id
                            && in_array($other->status, ['requested', 'approved', 'conditional'], true)
                            && $other->starts_at->lt($meeting->ends_at)
                            && $other->ends_at->gt($meeting->starts_at))
                        ->map(fn (Meeting $other) => [
                            'id' => $other->public_id,
                            'title' => $other->title,
                            'status' => $other->status,
                            'startsAt' => $other->starts_at->format('Y-m-d\TH:i'),
                            'endsAt' => $other->ends_at->format('Y-m-d\TH:i'),
                            'location' => $other->location,
                            'blocking' => in_array($other->status, ['approved', 'conditional'], true),
                        ])->values()
                        ->concat($externalEvents
                            ->filter(fn (ExternalCalendarEvent $event) => $event->is_busy
                                && $event->meeting_id !== $meeting->id
                                && $event->starts_at->lt($meeting->ends_at)
                                && $event->ends_at->gt($meeting->starts_at))
                            ->map(fn (ExternalCalendarEvent $event) => [
                                'id' => 'google-'.$event->id,
                                'title' => $event->title,
                                'status' => $event->review_status === 'approved' ? 'google_approved' : 'google_pending',
                                'startsAt' => $event->starts_at->format('Y-m-d\TH:i'),
                                'endsAt' => $event->ends_at->format('Y-m-d\TH:i'),
                                'location' => $event->location,
                                'blocking' => true,
                            ]))
                        ->values();

                    $resourceAnalysis = $meetingResources->analyze($meeting);

                    $mapping = $calendarMappings->get((string) $meeting->id);
                    $activeGoogleEvent = $mapping
                        && (string) data_get($mapping->metadata, 'calendar_id') === (string) $connection?->calendar_id
                        ? $meeting->calendarEvents->firstWhere('external_event_id', $mapping->external_id)
                        : null;

                    return [
                        'id' => $meeting->public_id,
                        'title' => $meeting->title,
                        'type' => $meeting->type,
                        'objective' => $meeting->objective,
                        'status' => $meeting->status,
                        'startsAt' => $meeting->starts_at->format('Y-m-d\TH:i'),
                        'endsAt' => $meeting->ends_at->format('Y-m-d\TH:i'),
                        'location' => $meeting->location,
                        'address' => $meeting->address,
                        'latitude' => $meeting->latitude,
                        'longitude' => $meeting->longitude,
                        'locationNotes' => $meeting->location_notes,
                        'expectedAttendees' => $meeting->expected_attendees,
                        'actualAttendees' => $meeting->actual_attendees,
                        'outcome' => $meeting->outcome,
                        'completedAt' => $meeting->completed_at?->toIso8601String(),
                        'leaderId' => $meeting->leader_person_id,
                        'territoryId' => $meeting->territory_unit_id,
                        'leader' => $meeting->leader?->name,
                        'territory' => $meeting->territory?->name,
                        'conflicts' => $conflicts,
                        'hasBlockingConflict' => $conflicts->contains('blocking', true),
                        'hasPotentialConflict' => $conflicts->isNotEmpty(),
                        'requirements' => $resourceAnalysis,
                        'hasResourceBlock' => $resourceAnalysis->contains('shortage', true),
                        'mobility' => $this->mobilityFor($meeting, $calendarMeetings),
                        'pendingChange' => ($change = $meeting->changeRequests->first()) ? [
                            'id' => $change->public_id,
                            'changes' => $change->proposed_changes,
                            'createdAt' => $change->created_at->toIso8601String(),
                        ] : null,
                        'googleSync' => $this->googleSyncState(
                            $meeting,
                            $connection,
                            $latestOutbox->get((string) $meeting->id),
                            $mapping,
                        ),
                        'googleHtmlLink' => $activeGoogleEvent?->html_link,
                    ];
                }),
            'externalEvents' => $externalEvents->map(fn (ExternalCalendarEvent $event) => [
                'id' => 'google-'.$event->id,
                'title' => $event->title,
                'startsAt' => $event->starts_at?->format('Y-m-d\TH:i'),
                'endsAt' => $event->ends_at?->format('Y-m-d\TH:i'),
                'location' => $event->location,
                'allDay' => $event->all_day,
                'isBusy' => $event->is_busy,
                'reviewStatus' => $event->review_status,
                'origin' => $event->origin,
                'htmlLink' => $event->html_link,
                'pendingReviewId' => $event->reviews->first()?->public_id,
            ]),
            'leaders' => Person::where('campaign_id', $campaignId)
                ->whereIn('status', ['verified', 'active'])
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name']),
            'territories' => TerritoryUnit::where('campaign_id', $campaignId)
                ->whereIn('type', ['commune', 'neighborhood', 'district', 'rural'])
                ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('id', $current->territoryIds()))
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
            'resources' => $meetingResources->eligibleResources($current->campaign)
                ->map(fn ($resource) => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'kind' => $resource->kind,
                    'unit' => $resource->unit,
                    'quantity' => (float) $resource->quantity,
                ])->values(),
            'filters' => ['status' => $status, 'month' => $month->format('Y-m'), 'date' => $selectedDate ?: null],
            'period' => [
                'month' => $month->format('Y-m'),
                'label' => ucfirst($month->translatedFormat('F Y')),
                'from' => $rangeStart->format('Y-m-d'),
                'to' => $rangeEnd->copy()->subDay()->format('Y-m-d'),
            ],
            'summary' => [
                'pending' => $calendarMeetings->where('status', 'requested')->count(),
                'approved' => $calendarMeetings->where('status', 'approved')->count(),
                'withConflicts' => $calendarMeetings->filter(function (Meeting $meeting) use ($calendarMeetings, $externalEvents) {
                    return $calendarMeetings->contains(fn (Meeting $other) => $other->id !== $meeting->id
                        && in_array($other->status, ['approved', 'conditional'], true)
                        && $other->starts_at->lt($meeting->ends_at)
                        && $other->ends_at->gt($meeting->starts_at))
                        || $externalEvents->contains(fn (ExternalCalendarEvent $event) => $event->is_busy
                            && $event->meeting_id !== $meeting->id
                            && $event->starts_at->lt($meeting->ends_at)
                            && $event->ends_at->gt($meeting->starts_at));
                })->count(),
                'googlePending' => $externalEvents->where('review_status', 'pending')->count(),
                'resourceAlerts' => $calendarMeetings->filter(
                    fn (Meeting $meeting) => in_array($meeting->status, ['requested', 'conditional'], true)
                        && $meetingResources->analyze($meeting)->contains('shortage', true)
                )->count(),
            ],
            'calendarIntegration' => [
                'connected' => (bool) $connection?->isReady(),
                'calendarName' => $connection?->calendar_name,
                'accountEmail' => $connection?->account_email,
                'status' => $connection?->status ?? 'not_configured',
            ],
        ]);
    }

    public function update(
        Request $request,
        string $publicId,
        CurrentCampaign $current,
        CalendarOutbox $outbox,
        MeetingResourceService $meetingResources,
    ): RedirectResponse {
        $current->authorize('meetings.manage');
        $meeting = $this->findMeeting($current, $publicId);
        $data = $this->validatedData($request);
        $requirements = $data['requirements'] ?? [];
        unset($data['requirements']);
        $current->authorizeTerritory(isset($data['territory_unit_id']) ? (int) $data['territory_unit_id'] : null);
        $this->validateRelations($current->campaign->id, $data);
        $old = $meeting->only(['title', 'starts_at', 'ends_at', 'status', 'location']);

        $scheduleChanged = ! $meeting->starts_at->equalTo(Carbon::parse($data['starts_at']))
            || ! $meeting->ends_at->equalTo(Carbon::parse($data['ends_at']));
        if ($meeting->status === 'approved' && $scheduleChanged) {
            $pending = MeetingChangeRequest::where('meeting_id', $meeting->id)->where('status', 'pending')->first();
            $values = [
                'campaign_id' => $current->campaign->id,
                'meeting_id' => $meeting->id,
                'requested_by' => $request->user()->id,
                'proposed_changes' => $data,
                'status' => 'pending',
            ];
            $pending
                ? $pending->update($values)
                : MeetingChangeRequest::create(['public_id' => (string) str()->ulid(), ...$values]);
            Audit::record('meeting.change_requested', $meeting, ['proposed_changes' => $data], $old, $current->campaign);

            return back()->with('success', 'El nuevo horario quedó pendiente de aprobación. La agenda vigente no cambió.');
        }

        DB::transaction(function () use ($meeting, $data, $requirements, $meetingResources) {
            $meeting->update($data);

            if ($meeting->status !== 'approved') {
                $meetingResources->syncRequirements($meeting->fresh('campaign'), $requirements);
            }
        });
        Audit::record('meeting.updated', $meeting, $meeting->only(['title', 'starts_at', 'ends_at', 'status', 'location']), $old, $current->campaign);
        if ($meeting->status === 'approved') {
            $outbox->meetingUpsert($meeting);
            ProcessCalendarOutbox::dispatch((int) $meeting->campaign_id);
        }

        return back()->with('success', 'La reunión fue actualizada.');
    }

    public function store(Request $request, CurrentCampaign $current, MeetingResourceService $meetingResources): RedirectResponse
    {
        $current->authorize('meetings.create');
        $campaignId = $current->campaign->id;
        $data = $this->validatedData($request);
        $requirements = $data['requirements'] ?? [];
        unset($data['requirements']);
        $current->authorizeTerritory(isset($data['territory_unit_id']) ? (int) $data['territory_unit_id'] : null);

        if (! empty($data['leader_person_id'])) {
            abort_unless(Person::where('campaign_id', $campaignId)->whereKey($data['leader_person_id'])->exists(), 422);
        }
        if (! empty($data['territory_unit_id'])) {
            abort_unless(TerritoryUnit::where('campaign_id', $campaignId)->whereKey($data['territory_unit_id'])->exists(), 422);
        }

        $meeting = DB::transaction(function () use ($data, $requirements, $campaignId, $request, $meetingResources) {
            $meeting = Meeting::create([
                ...$data,
                'public_id' => (string) str()->ulid(),
                'campaign_id' => $campaignId,
                'requested_by' => $request->user()->id,
                'status' => 'requested',
            ]);
            $meeting->load('campaign');
            $meetingResources->syncRequirements($meeting, $requirements);

            return $meeting;
        });

        Audit::record('meeting.requested', $meeting, ['status' => 'requested'], campaign: $current->campaign);
        app(CampaignNotifier::class)->notifyPermission(
            $current->campaign->id,
            'meetings.approve',
            new CampaignActivityNotification(
                $current->campaign->id,
                'Nueva reunión por revisar',
                "{$meeting->title} quedó registrada y requiere decisión de Agenda.",
                '/meetings?status=requested&date='.$meeting->starts_at->format('Y-m-d'),
                'meeting',
                true,
                'Nueva reunión por revisar',
            ),
            $request->user()->id,
        );

        return back()->with('success', 'La solicitud de reunión quedó registrada.');
    }

    public function approve(
        Request $request,
        string $publicId,
        CurrentCampaign $current,
        CalendarConflictService $conflicts,
        CalendarOutbox $outbox,
        MeetingResourceService $meetingResources,
    ): RedirectResponse {
        $current->authorize('meetings.approve');
        $meeting = $this->findMeeting($current, $publicId);

        $conflict = $conflicts->find(
            $current->campaign->id,
            $meeting->starts_at,
            $meeting->ends_at,
            $meeting->id,
        )->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'meeting' => "No se puede aprobar: se cruza con «{$conflict['title']}» entre "
                    .$conflict['starts_at']->format('H:i').' y '.$conflict['ends_at']->format('H:i').'.',
            ]);
        }

        $old = ['status' => $meeting->status];
        DB::transaction(function () use ($meeting, $request, $meetingResources) {
            $meetingResources->allocate($meeting, $request->user()->id);
            $meeting->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'approval_notes' => $request->string('approval_notes')->toString() ?: null,
            ]);
        });

        Audit::record('meeting.approved', $meeting, ['status' => 'approved'], $old, $current->campaign);
        $meeting->load('requester');
        app(CampaignNotifier::class)->notifyUsers(
            collect([$meeting->requester]),
            new CampaignActivityNotification(
                $current->campaign->id,
                'Reunión aprobada',
                "{$meeting->title} fue incorporada a la agenda.",
                '/meetings?date='.$meeting->starts_at->format('Y-m-d'),
                'meeting',
            ),
            $request->user()->id,
        );
        $outbox->meetingUpsert($meeting);
        ProcessCalendarOutbox::dispatch((int) $meeting->campaign_id);

        $connected = CalendarConnection::where('campaign_id', $current->campaign->id)
            ->where('status', 'active')
            ->first()?->isReady();

        return back()->with(
            'success',
            $connected
                ? 'La reunión fue aprobada. La publicación en Google Calendar está pendiente de confirmación.'
                : 'La reunión fue aprobada. Se publicará cuando conectes un calendario escribible.',
        );
    }

    public function reject(
        Request $request,
        string $publicId,
        CurrentCampaign $current,
        MeetingResourceService $meetingResources,
    ): RedirectResponse {
        $current->authorize('meetings.approve');
        $data = $request->validate(['approval_notes' => ['required', 'string', 'max:2000']]);
        $meeting = $this->findMeeting($current, $publicId);
        $old = ['status' => $meeting->status];
        $meeting->update([
            'status' => 'rejected',
            'approval_notes' => $data['approval_notes'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        $meetingResources->reject($meeting);
        Audit::record('meeting.rejected', $meeting, ['status' => 'rejected'], $old, $current->campaign);
        $meeting->load('requester');
        app(CampaignNotifier::class)->notifyUsers(
            collect([$meeting->requester]),
            new CampaignActivityNotification(
                $current->campaign->id,
                'Reunión rechazada',
                "{$meeting->title} fue rechazada. Motivo: {$data['approval_notes']}",
                '/meetings?date='.$meeting->starts_at->format('Y-m-d'),
                'meeting',
            ),
            $request->user()->id,
        );

        return back()->with('success', 'La solicitud fue rechazada y quedó auditada.');
    }

    public function complete(Request $request, string $publicId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('meetings.manage');
        $data = $request->validate([
            'actual_attendees' => ['required', 'integer', 'min:0', 'max:100000'],
            'outcome' => ['nullable', 'string', 'max:3000'],
        ]);
        $meeting = $this->findMeeting($current, $publicId);

        if ($meeting->status === 'completed') {
            return back()->with('success', 'La reunión ya estaba marcada como realizada.');
        }

        if ($meeting->status !== 'approved') {
            throw ValidationException::withMessages([
                'meeting' => 'Solo una reunión confirmada puede marcarse como realizada.',
            ]);
        }

        $old = $meeting->only(['status', 'actual_attendees', 'outcome', 'completed_at']);
        $meeting->update([
            'status' => 'completed',
            'actual_attendees' => $data['actual_attendees'],
            'outcome' => $data['outcome'] ?? null,
            'completed_at' => now(),
            'completed_by' => $request->user()->id,
        ]);

        Audit::record(
            'meeting.completed',
            $meeting,
            $meeting->only(['status', 'actual_attendees', 'outcome', 'completed_at']),
            $old,
            $current->campaign,
        );
        app(CampaignNotifier::class)->notifyPermissions(
            $current->campaign->id,
            ['analytics.view', 'meetings.approve'],
            new CampaignActivityNotification(
                $current->campaign->id,
                'Reunión realizada',
                "{$meeting->title} fue marcada como realizada con {$meeting->actual_attendees} asistentes reales.",
                '/meetings?date='.$meeting->starts_at->format('Y-m-d'),
                'metrics',
            ),
            $request->user()->id,
        );

        return back()->with('success', 'La reunión fue marcada como realizada.');
    }

    public function destroy(
        string $publicId,
        CurrentCampaign $current,
        CalendarOutbox $outbox,
        MeetingResourceService $meetingResources,
    ): RedirectResponse {
        $current->authorize('meetings.delete');
        $meeting = $this->findMeeting($current, $publicId);
        DB::transaction(function () use ($meeting, $current, $outbox, $meetingResources) {
            $meetingResources->cancelAllocations($meeting, request()->user()->id);
            Audit::record('meeting.deleted', $meeting, ['deleted' => true], campaign: $current->campaign);
            $outbox->meetingDelete($meeting);
            $meeting->delete();
        });
        ProcessCalendarOutbox::dispatch((int) $meeting->campaign_id);

        return back()->with('success', 'La reunión fue eliminada.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'type' => ['required', 'string', 'max:60'],
            'objective' => ['nullable', 'string', 'max:3000'],
            'location' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location_notes' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'expected_attendees' => ['required', 'integer', 'min:0', 'max:100000'],
            'leader_person_id' => ['nullable', 'integer'],
            'territory_unit_id' => ['nullable', 'integer'],
            'requirements' => ['nullable', 'array', 'max:100'],
            'requirements.*.resource_id' => ['required', 'integer', 'distinct'],
            'requirements.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'requirements.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function validateRelations(int $campaignId, array $data): void
    {
        if (! empty($data['leader_person_id'])) {
            abort_unless(Person::where('campaign_id', $campaignId)->whereKey($data['leader_person_id'])->exists(), 422);
        }
        if (! empty($data['territory_unit_id'])) {
            abort_unless(TerritoryUnit::where('campaign_id', $campaignId)->whereKey($data['territory_unit_id'])->exists(), 422);
        }
    }

    private function findMeeting(CurrentCampaign $current, string $publicId): Meeting
    {
        return Meeting::where('campaign_id', $current->campaign->id)
            ->where('public_id', $publicId)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('territory_unit_id', $current->territoryIds()))
            ->firstOrFail();
    }

    private function mobilityFor(Meeting $meeting, Collection $meetings): array
    {
        $confirmed = $meetings
            ->filter(fn (Meeting $other) => $other->id !== $meeting->id
                && in_array($other->status, ['approved', 'conditional'], true)
                && $other->starts_at->toDateString() === $meeting->starts_at->toDateString());

        $previous = $confirmed
            ->filter(fn (Meeting $other) => $other->ends_at->lte($meeting->starts_at))
            ->sortByDesc(fn (Meeting $other) => $other->ends_at->getTimestamp())
            ->first();
        $next = $confirmed
            ->filter(fn (Meeting $other) => $other->starts_at->gte($meeting->ends_at))
            ->sortBy(fn (Meeting $other) => $other->starts_at->getTimestamp())
            ->first();

        return [
            'before' => $previous ? $this->travelLeg($previous, $meeting) : null,
            'after' => $next ? $this->travelLeg($meeting, $next) : null,
        ];
    }

    private function travelLeg(Meeting $from, Meeting $to): array
    {
        $gapMinutes = max(0, (int) round($from->ends_at->diffInMinutes($to->starts_at, false)));
        $distance = $this->distanceInKilometers($from, $to);
        $estimatedMinutes = $distance !== null
            ? (int) ceil(($distance / 25) * 60) + 10
            : null;

        return [
            'from' => $this->travelPoint($from),
            'to' => $this->travelPoint($to),
            'gapMinutes' => $gapMinutes,
            'distanceKm' => $distance !== null ? round($distance, 1) : null,
            'estimatedMinutes' => $estimatedMinutes,
            'risk' => $estimatedMinutes !== null && $gapMinutes < $estimatedMinutes,
            'assessment' => $estimatedMinutes === null
                ? 'unknown'
                : ($gapMinutes < $estimatedMinutes ? 'insufficient' : ($gapMinutes - $estimatedMinutes < 15 ? 'tight' : 'comfortable')),
        ];
    }

    private function travelPoint(Meeting $meeting): array
    {
        return [
            'id' => $meeting->public_id,
            'title' => $meeting->title,
            'location' => $meeting->location,
            'address' => $meeting->address,
            'startsAt' => $meeting->starts_at->format('Y-m-d\TH:i'),
            'endsAt' => $meeting->ends_at->format('Y-m-d\TH:i'),
        ];
    }

    private function googleSyncState(
        Meeting $meeting,
        ?CalendarConnection $connection,
        ?OutboxEvent $outbox,
        ?IntegrationMapping $mapping,
    ): string {
        if ($meeting->status !== 'approved') {
            return $meeting->status === 'requested' ? 'after_approval' : 'not_applicable';
        }
        if (! $connection?->isReady()) {
            return 'not_connected';
        }
        if (
            $meeting->google_event_id
            && $meeting->google_etag
            && $mapping
            && (string) $mapping->external_id === (string) $meeting->google_event_id
            && (string) data_get($mapping->metadata, 'calendar_id') === (string) $connection->calendar_id
        ) {
            return 'synced';
        }
        if ($outbox?->last_error) {
            return 'failed';
        }

        return 'queued';
    }

    private function distanceInKilometers(Meeting $from, Meeting $to): ?float
    {
        if ($from->latitude === null || $from->longitude === null || $to->latitude === null || $to->longitude === null) {
            return null;
        }

        $earthRadius = 6371;
        $latFrom = deg2rad($from->latitude);
        $latTo = deg2rad($to->latitude);
        $latDelta = deg2rad($to->latitude - $from->latitude);
        $lonDelta = deg2rad($to->longitude - $from->longitude);
        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
