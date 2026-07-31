<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Meeting;
use App\Models\ReferralRelationship;
use App\Models\VotingPlace;
use App\Models\VotingTable;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PeopleController extends Controller
{
    public function index(Request $request, CurrentCampaign $current): Response
    {
        $current->authorize('territorial.view');
        $search = Str::lower(Str::ascii($request->string('search')->trim()->toString()));
        $status = $request->string('status')->toString();

        $people = Person::query()
            ->with(['votingPlace:id,name,commune,tables_count', 'votingTable:id,number'])
            ->withCount('children')
            ->where('campaign_id', $current->campaign->id)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                'votingPlace',
                fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
            ))
            ->when($search, fn ($query) => $query->where('search_name', 'like', "%{$search}%"))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Person $person) => $this->serializePerson($person));

        return Inertia::render('People/Index', [
            'people' => $people,
            'places' => VotingPlace::where('campaign_id', $current->campaign->id)
                ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('territory_unit_id', $current->territoryIds()))
                ->orderBy('commune')
                ->orderBy('name')
                ->get(['id', 'name', 'commune', 'tables_count']),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $status,
            ],
            'statuses' => ['pending', 'verified', 'active', 'inactive', 'rejected', 'withdrawn'],
        ]);
    }

    public function store(Request $request, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('territorial.manage');
        $data = $this->validatedData($request);
        $this->authorizePlace($current, $data['voting_place_id'] ?? null);
        [$documentNumber, $documentHash] = $this->documentIdentity($data['document_number'] ?? null);
        $this->ensureDocumentIsAvailable($current->campaign->id, $documentHash);
        $tableId = $this->resolveTable($current->campaign->id, $data['voting_place_id'] ?? null, $data['voting_table_number'] ?? null);

        $person = Person::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $current->campaign->id,
            'voting_place_id' => $data['voting_place_id'] ?? null,
            'voting_table_id' => $tableId,
            'name' => trim($data['name']),
            'search_name' => Str::lower(Str::ascii(trim($data['name']))),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'document_number' => $documentNumber,
            'document_hash' => $documentHash,
            'document_last_four' => $documentNumber ? substr($documentNumber, -4) : null,
            'status' => $data['status'],
            'verified_at' => in_array($data['status'], ['verified', 'active'], true) ? now() : null,
        ]);

        Audit::record('person.created', $person, ['status' => $person->status], campaign: $current->campaign);

        return back()->with('success', 'La persona fue creada correctamente.');
    }

    public function show(string $publicId, CurrentCampaign $current): Response
    {
        $current->authorize('territorial.view');
        $person = $this->findPerson($current, $publicId);
        $person->load([
            'votingPlace:id,name,commune,address,territory_unit_id',
            'votingTable:id,number',
            'children.child:id,public_id,name,status',
            'parentRelationships.parent:id,public_id,name,status',
        ]);

        [$descendants, $networkDepth] = $this->networkMetrics($current->campaign->id, $person->id);
        $meetingsQuery = Meeting::query()
            ->where('campaign_id', $current->campaign->id)
            ->where('leader_person_id', $person->id);
        $meetingsLed = (clone $meetingsQuery)->count();
        $meetings = $meetingsQuery
            ->latest('starts_at')
            ->limit(8)
            ->get(['public_id', 'title', 'type', 'status', 'starts_at', 'location', 'expected_attendees', 'actual_attendees']);
        $attendanceCount = DB::table('attendances')
            ->where('campaign_id', $current->campaign->id)
            ->where('person_id', $person->id)
            ->count();
        $consent = DB::table('consents')
            ->where('campaign_id', $current->campaign->id)
            ->where('person_id', $person->id)
            ->whereNull('revoked_at')
            ->latest('accepted_at')
            ->first(['version', 'channel', 'accepted_at']);
        $roles = DB::table('territorial_roles')
            ->leftJoin('territory_units', 'territorial_roles.territory_unit_id', '=', 'territory_units.id')
            ->where('territorial_roles.campaign_id', $current->campaign->id)
            ->where('territorial_roles.person_id', $person->id)
            ->orderByDesc('territorial_roles.approved_at')
            ->get([
                'territorial_roles.role',
                'territorial_roles.title',
                'territorial_roles.status',
                'territorial_roles.approved_at',
                'territory_units.name as territory',
            ]);

        return Inertia::render('People/Show', [
            'person' => [
                'id' => $person->public_id,
                'name' => $person->name,
                'email' => $person->email,
                'phone' => $person->phone,
                'document' => $person->document_number ?: 'Sin registrar',
                'status' => $person->status,
                'isReferralNode' => (bool) $person->is_referral_node,
                'verifiedAt' => $person->verified_at?->toIso8601String(),
                'createdAt' => $person->created_at->toIso8601String(),
                'place' => $person->votingPlace?->name,
                'commune' => $person->votingPlace?->commune,
                'placeAddress' => $person->votingPlace?->address,
                'table' => $person->votingTable?->number,
                'parent' => $person->parentRelationships->first()?->parent
                    ? [
                        'id' => $person->parentRelationships->first()->parent->public_id,
                        'name' => $person->parentRelationships->first()->parent->name,
                        'status' => $person->parentRelationships->first()->parent->status,
                    ]
                    : null,
                'directReferrals' => $person->children->map(fn (ReferralRelationship $relationship) => [
                    'id' => $relationship->child->public_id,
                    'name' => $relationship->child->name,
                    'status' => $relationship->child->status,
                ])->values(),
                'metrics' => [
                    'directReferrals' => $person->children->count(),
                    'descendants' => $descendants,
                    'networkDepth' => $networkDepth,
                    'meetingsLed' => $meetingsLed,
                    'attendances' => $attendanceCount,
                ],
                'consent' => $consent ? [
                    'version' => $consent->version,
                    'channel' => $consent->channel,
                    'acceptedAt' => $consent->accepted_at,
                ] : null,
                'roles' => $roles,
                'meetings' => $meetings->map(fn (Meeting $meeting) => [
                    'id' => $meeting->public_id,
                    'title' => $meeting->title,
                    'type' => $meeting->type,
                    'status' => $meeting->status,
                    'startsAt' => $meeting->starts_at->toIso8601String(),
                    'location' => $meeting->location,
                    'expectedAttendees' => $meeting->expected_attendees,
                    'actualAttendees' => $meeting->actual_attendees,
                ]),
            ],
        ]);
    }

    public function update(Request $request, string $publicId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('territorial.manage');
        $person = $this->findPerson($current, $publicId);
        $data = $this->validatedData($request);
        $this->authorizePlace($current, $data['voting_place_id'] ?? null);
        [$documentNumber, $documentHash] = $this->documentIdentity($data['document_number'] ?? null);
        if ($documentHash) {
            $this->ensureDocumentIsAvailable($current->campaign->id, $documentHash, $person->id);
        }
        $tableId = $this->resolveTable($current->campaign->id, $data['voting_place_id'] ?? null, $data['voting_table_number'] ?? null);
        $old = $person->only(['name', 'status', 'voting_place_id', 'voting_table_id']);

        $person->update([
            'voting_place_id' => $data['voting_place_id'] ?? null,
            'voting_table_id' => $tableId,
            'name' => trim($data['name']),
            'search_name' => Str::lower(Str::ascii(trim($data['name']))),
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'document_number' => $documentNumber ?: $person->document_number,
            'document_hash' => $documentHash ?: $person->document_hash,
            'document_last_four' => $documentNumber ? substr($documentNumber, -4) : $person->document_last_four,
            'status' => $data['status'],
            'verified_at' => in_array($data['status'], ['verified', 'active'], true)
                ? ($person->verified_at ?? now())
                : $person->verified_at,
        ]);

        Audit::record('person.updated', $person, $person->only(['name', 'status', 'voting_place_id', 'voting_table_id']), $old, $current->campaign);

        return back()->with('success', 'La información de la persona fue actualizada.');
    }

    public function verify(Request $request, string $publicId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('territorial.verify');
        $request->validate(['consent_confirmed' => ['accepted']]);
        $person = $this->findPerson($current, $publicId);
        $consentText = 'El titular autorizó de manera previa, expresa e informada el tratamiento de sus datos personales y sensibles para la gestión de esta campaña.';

        DB::transaction(function () use ($person, $request, $current, $consentText) {
            $hasConsent = DB::table('consents')
                ->where('campaign_id', $current->campaign->id)
                ->where('person_id', $person->id)
                ->whereNull('revoked_at')
                ->exists();
            if (! $hasConsent) {
                DB::table('consents')->insert([
                    'campaign_id' => $current->campaign->id,
                    'person_id' => $person->id,
                    'version' => '2026-07-admin-v1',
                    'text_hash' => hash('sha256', $consentText),
                    'channel' => 'admin_attestation',
                    'accepted_at' => now(),
                    'ip_hash' => $request->ip() ? hash_hmac('sha256', $request->ip(), config('app.key')) : null,
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $oldStatus = $person->status;
            $person->update(['status' => 'verified', 'verified_at' => now()]);
            Audit::record('person.verified', $person, ['status' => 'verified'], ['status' => $oldStatus], $current->campaign);
        });

        return back()->with('success', 'La persona fue verificada y la decisión quedó auditada.');
    }

    public function destroy(string $publicId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('territorial.delete');
        $person = $this->findPerson($current, $publicId);
        Audit::record('person.deleted', $person, ['deleted_at' => now()->toIso8601String()], campaign: $current->campaign);
        $person->delete();

        return back()->with('success', 'La persona fue retirada de la campaña.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:30'],
            'document_number' => ['nullable', 'string', 'max:30'],
            'voting_place_id' => ['nullable', 'integer'],
            'voting_table_number' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['pending', 'verified', 'active', 'inactive', 'rejected', 'withdrawn'])],
        ]);
    }

    private function documentIdentity(?string $document): array
    {
        $normalized = preg_replace('/\D+/', '', (string) $document);

        return $normalized
            ? [$normalized, hash_hmac('sha256', $normalized, config('app.key'))]
            : [null, null];
    }

    private function ensureDocumentIsAvailable(int $campaignId, ?string $hash, ?int $exceptId = null): void
    {
        if (! $hash) {
            return;
        }
        $exists = Person::withTrashed()
            ->where('campaign_id', $campaignId)
            ->where('document_hash', $hash)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['document_number' => 'La cédula ya pertenece a otra persona de esta campaña.']);
        }
    }

    private function resolveTable(int $campaignId, ?int $placeId, ?int $tableNumber): ?int
    {
        if (! $placeId && ! $tableNumber) {
            return null;
        }
        $place = VotingPlace::where('campaign_id', $campaignId)->whereKey($placeId)->firstOrFail();
        if (! $tableNumber) {
            return null;
        }

        return VotingTable::where('campaign_id', $campaignId)
            ->where('voting_place_id', $place->id)
            ->where('number', $tableNumber)
            ->value('id')
            ?? throw ValidationException::withMessages(['voting_table_number' => 'La mesa no existe en el puesto seleccionado.']);
    }

    private function findPerson(CurrentCampaign $current, string $publicId): Person
    {
        return Person::query()
            ->where('campaign_id', $current->campaign->id)
            ->where('public_id', $publicId)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                'votingPlace',
                fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
            ))
            ->firstOrFail();
    }

    private function authorizePlace(CurrentCampaign $current, ?int $placeId): void
    {
        if ($current->hasGlobalTerritorialScope()) {
            return;
        }
        $territoryId = $placeId
            ? VotingPlace::where('campaign_id', $current->campaign->id)->whereKey($placeId)->value('territory_unit_id')
            : null;
        $current->authorizeTerritory($territoryId ? (int) $territoryId : null);
    }

    private function networkMetrics(int $campaignId, int $personId): array
    {
        $childrenByParent = ReferralRelationship::query()
            ->where('campaign_id', $campaignId)
            ->whereNull('ended_at')
            ->get(['parent_person_id', 'child_person_id'])
            ->groupBy('parent_person_id');
        $frontier = [$personId];
        $visited = [$personId => true];
        $descendants = 0;
        $depth = 0;

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $parentId) {
                foreach ($childrenByParent->get($parentId, collect()) as $relationship) {
                    $childId = (int) $relationship->child_person_id;

                    if (isset($visited[$childId])) {
                        continue;
                    }

                    $visited[$childId] = true;
                    $next[] = $childId;
                    $descendants++;
                }
            }

            if ($next !== []) {
                $depth++;
            }

            $frontier = $next;
        }

        return [$descendants, $depth];
    }

    private function serializePerson(Person $person): array
    {
        return [
            'id' => $person->public_id,
            'name' => $person->name,
            'email' => $person->email,
            'phone' => $person->phone,
            'document' => $person->document_number ?: 'Sin registrar',
            'status' => $person->status,
            'isReferralNode' => (bool) $person->is_referral_node,
            'placeId' => $person->voting_place_id,
            'place' => $person->votingPlace?->name,
            'commune' => $person->votingPlace?->commune,
            'tablesCount' => $person->votingPlace?->tables_count ?? 0,
            'table' => $person->votingTable?->number,
            'children' => $person->children_count,
            'createdAt' => $person->created_at->toIso8601String(),
        ];
    }
}
