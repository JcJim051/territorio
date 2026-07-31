<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PublicToken;
use App\Models\TerritoryUnit;
use App\Services\ReferralTokenService;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ReferralNodeController extends Controller
{
    public function index(Request $request, CurrentCampaign $current): Response
    {
        $current->authorize('territorial.tokens.manage');
        $search = Str::lower(Str::ascii($request->string('search')->trim()->toString()));
        $status = $request->string('status')->toString();

        $nodes = Person::query()
            ->with(['votingPlace:id,name,commune,territory_unit_id', 'publicTokens' => fn ($query) => $query->latest()])
            ->withCount('children')
            ->where('campaign_id', $current->campaign->id)
            ->where('is_referral_node', true)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                'votingPlace',
                fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
            ))
            ->when($search, fn ($query) => $query->where('search_name', 'like', "%{$search}%"))
            ->when($status === 'active', fn ($query) => $query->whereHas('publicTokens', fn ($tokens) => $this->activeToken($tokens)))
            ->when($status === 'inactive', fn ($query) => $query->whereDoesntHave('publicTokens', fn ($tokens) => $this->activeToken($tokens)))
            ->orderByDesc('promoted_at')
            ->paginate(18)
            ->withQueryString()
            ->through(fn (Person $person) => $this->serializeNode($person));

        $eligible = Person::query()
            ->with('votingPlace:id,name,commune,territory_unit_id')
            ->where('campaign_id', $current->campaign->id)
            ->where('is_referral_node', false)
            ->whereIn('status', ['verified', 'active'])
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                'votingPlace',
                fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
            ))
            ->orderBy('search_name')
            ->limit(500)
            ->get()
            ->map(fn (Person $person) => [
                'id' => $person->public_id,
                'name' => $person->name,
                'document' => $person->document_number ?: 'Sin registrar',
                'commune' => $person->votingPlace?->commune,
                'place' => $person->votingPlace?->name,
                'territoryId' => $person->votingPlace?->territory_unit_id,
            ]);

        return Inertia::render('Territorial/Nodes', [
            'nodes' => $nodes,
            'eligiblePeople' => $eligible,
            'territories' => $this->territories($current),
            'filters' => ['search' => $request->string('search')->toString(), 'status' => $status],
            'defaults' => ['expiresAt' => now($current->campaign->timezone ?: 'America/Bogota')->addMonths(6)->format('Y-m-d')],
        ]);
    }

    public function promote(Request $request, string $publicId, CurrentCampaign $current, ReferralTokenService $service): RedirectResponse
    {
        $current->authorize('territorial.tokens.manage');
        $person = $this->findPerson($publicId, $current);
        $configuration = $this->configuration($request, $current);
        [$token] = $service->promote($person, $request->user(), $configuration);
        Audit::record('referral_node.promoted', $person, [
            'token_id' => $token->id,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'max_uses' => $token->max_uses,
            'territorial_scope' => $token->territorial_scope,
        ], campaign: $current->campaign);

        return back()->with('success', $person->name.' ahora es un nodo y su enlace está listo para compartir.');
    }

    public function update(Request $request, string $publicId, int $tokenId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('territorial.tokens.manage');
        $person = $this->findPerson($publicId, $current);
        $token = $this->findToken($tokenId, $person);
        abort_if($token->revoked_at, 410, 'El enlace está revocado.');
        $configuration = $this->configuration($request, $current);
        if ($configuration['max_uses'] && $configuration['max_uses'] < $token->uses) {
            throw ValidationException::withMessages(['max_uses' => 'El límite no puede ser menor que los usos ya registrados.']);
        }
        $old = $token->only(['label', 'expires_at', 'max_uses', 'territorial_scope']);
        $token->update([
            'label' => $configuration['label'],
            'expires_at' => $configuration['expires_at'],
            'max_uses' => $configuration['max_uses'],
            'territorial_scope' => $configuration['territory_unit_ids']
                ? ['territory_unit_ids' => $configuration['territory_unit_ids']]
                : null,
        ]);
        Audit::record('referral_token.updated', $token, $token->only(['label', 'expires_at', 'max_uses', 'territorial_scope']), $old, $current->campaign);

        return back()->with('success', 'La configuración del enlace fue actualizada.');
    }

    public function rotate(Request $request, string $publicId, CurrentCampaign $current, ReferralTokenService $service): RedirectResponse
    {
        $current->authorize('territorial.tokens.manage');
        $person = $this->findPerson($publicId, $current);
        $configuration = $this->configuration($request, $current);
        [$token] = $service->rotate($person, $request->user(), $configuration);
        Audit::record('referral_token.rotated', $token, ['owner_person_id' => $person->id], campaign: $current->campaign);

        return back()->with('success', 'El enlace anterior fue revocado y se generó uno nuevo.');
    }

    public function revoke(string $publicId, int $tokenId, CurrentCampaign $current): RedirectResponse
    {
        $current->authorize('territorial.tokens.manage');
        $person = $this->findPerson($publicId, $current);
        $token = $this->findToken($tokenId, $person);
        if (! $token->revoked_at) {
            $token->update(['revoked_at' => now()]);
            Audit::record('referral_token.revoked', $token, ['revoked_at' => $token->revoked_at], campaign: $current->campaign);
        }

        return back()->with('success', 'El enlace fue revocado y ya no admite registros.');
    }

    public function demote(string $publicId, CurrentCampaign $current, ReferralTokenService $service): RedirectResponse
    {
        $current->authorize('territorial.tokens.manage');
        $person = $this->findPerson($publicId, $current);
        $service->demote($person);
        Audit::record('referral_node.demoted', $person, ['is_referral_node' => false], campaign: $current->campaign);

        return back()->with('success', 'La persona dejó de ser un nodo de captación.');
    }

    private function configuration(Request $request, CurrentCampaign $current): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:180'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'territory_unit_ids' => ['present', 'array'],
            'territory_unit_ids.*' => ['integer'],
        ]);
        $ids = collect($data['territory_unit_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $allowed = collect($this->territories($current))->pluck('id')->map(fn ($id) => (int) $id);
        if ($ids->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['territory_unit_ids' => 'Uno de los territorios no pertenece al alcance autorizado.']);
        }

        return [
            'label' => trim((string) ($data['label'] ?? '')),
            'expires_at' => filled($data['expires_at'] ?? null)
                ? Carbon::parse($data['expires_at'], $current->campaign->timezone ?: 'America/Bogota')->endOfDay()->utc()
                : null,
            'max_uses' => isset($data['max_uses']) ? (int) $data['max_uses'] : null,
            'territory_unit_ids' => $ids->all(),
        ];
    }

    private function territories(CurrentCampaign $current): array
    {
        return TerritoryUnit::where('campaign_id', $current->campaign->id)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereIn('id', $current->territoryIds()))
            ->whereIn('id', DB::table('voting_places')
                ->where('campaign_id', $current->campaign->id)
                ->whereNotNull('territory_unit_id')
                ->select('territory_unit_id'))
            ->whereIn('type', ['commune', 'district', 'neighborhood', 'rural'])
            ->orderBy('type')->orderBy('name')->get(['id', 'name', 'type'])->all();
    }

    private function findPerson(string $publicId, CurrentCampaign $current): Person
    {
        return Person::where('campaign_id', $current->campaign->id)
            ->where('public_id', $publicId)
            ->when(! $current->hasGlobalTerritorialScope(), fn ($query) => $query->whereHas(
                'votingPlace', fn ($place) => $place->whereIn('territory_unit_id', $current->territoryIds())
            ))
            ->firstOrFail();
    }

    private function findToken(int $tokenId, Person $person): PublicToken
    {
        return PublicToken::where('campaign_id', $person->campaign_id)
            ->where('owner_person_id', $person->id)
            ->findOrFail($tokenId);
    }

    private function serializeNode(Person $person): array
    {
        $token = $person->publicTokens->first(fn (PublicToken $item) => $item->isUsable());
        $latest = $token ?: $person->publicTokens->first();

        return [
            'id' => $person->public_id,
            'name' => $person->name,
            'document' => $person->document_number ?: 'Sin registrar',
            'status' => $person->status,
            'commune' => $person->votingPlace?->commune,
            'place' => $person->votingPlace?->name,
            'children' => $person->children_count,
            'promotedAt' => $person->promoted_at?->toIso8601String(),
            'token' => $latest ? [
                'id' => $latest->id,
                'label' => $latest->label,
                'active' => $latest->isUsable(),
                'uses' => $latest->uses,
                'maxUses' => $latest->max_uses,
                'expiresAt' => $latest->expires_at?->format('Y-m-d'),
                'revokedAt' => $latest->revoked_at?->toIso8601String(),
                'territoryIds' => $latest->territorial_scope['territory_unit_ids'] ?? [],
                'link' => $latest->token_ciphertext
                    ? route('public.invitations.show', ['token' => $latest->token_ciphertext])
                    : null,
            ] : null,
        ];
    }

    private function activeToken($query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }
}
