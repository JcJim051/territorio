<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use App\Support\OfficialRoleProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function index(Request $request, CurrentCampaign $current): Response
    {
        $this->authorizeSuperAdmin($request);

        return Inertia::render('Admin/Campaigns/Index', [
            'campaignsList' => Campaign::query()
                ->with('organization:id,name')
                ->withCount(['memberships', 'persons', 'meetings'])
                ->orderByRaw("case when status = 'active' then 0 else 1 end")
                ->orderBy('election_at')
                ->orderBy('candidate_name')
                ->get()
                ->map(fn (Campaign $campaign) => $this->serialize($campaign)),
            'modules' => [
                ['key' => 'territorial', 'label' => 'Gestión territorial'],
                ['key' => 'meetings', 'label' => 'Agenda y reuniones'],
                ['key' => 'inventory', 'label' => 'Inventario y logística'],
                ['key' => 'analytics', 'label' => 'Analítica'],
                ['key' => 'calendar', 'label' => 'Google Calendar'],
            ],
            'timezones' => ['America/Bogota', 'America/Lima', 'America/Guayaquil', 'America/Panama'],
        ]);
    }

    public function store(Request $request, CurrentCampaign $current, OfficialRoleProvisioner $provisioner): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $this->validatedData($request, $current);
        $slug = Str::slug($data['slug'] ?: $data['name']);
        $this->ensureUniqueSlug($current->campaign->organization_id, $slug);

        DB::transaction(function () use ($data, $slug, $request, $current, $provisioner) {
            $campaign = Campaign::create([
                'organization_id' => $current->campaign->organization_id,
                'name' => $data['name'],
                'slug' => $slug,
                'candidate_name' => $data['candidate_name'],
                'office' => $data['office'],
                'territory' => $data['territory'],
                'starts_at' => $data['starts_at'] ?: null,
                'election_at' => $data['election_at'] ?: null,
                'status' => $data['status'],
                'timezone' => $data['timezone'],
                'theme_color' => strtoupper($data['theme_color']),
                'enabled_modules' => array_values(array_unique($data['enabled_modules'])),
                'settings' => ['node_activation' => 'approval', 'driver_agenda_days' => 7],
            ]);

            $role = $provisioner->provision($campaign)['technical-administrator'];
            CampaignMembership::create([
                'campaign_id' => $campaign->id,
                'user_id' => $request->user()->id,
                'campaign_role_id' => $role->id,
                'is_active' => true,
            ]);

            Audit::record('campaign.created', $campaign, $this->auditValues($campaign), campaign: $campaign);
        });

        return back()->with('success', 'La campaña fue creada, compartimentada y asignada a tu cuenta.');
    }

    public function update(Request $request, int $campaignId, CurrentCampaign $current): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $campaign = Campaign::findOrFail($campaignId);
        $data = $this->validatedData($request, $current, $campaign);
        $slug = Str::slug($data['slug'] ?: $data['name']);
        $this->ensureUniqueSlug($campaign->organization_id, $slug, $campaign);

        if ($campaign->id === $current->campaign->id && $data['status'] !== 'active') {
            throw ValidationException::withMessages([
                'status' => 'No puedes desactivar la campaña que estás gestionando. Cambia primero a otra campaña.',
            ]);
        }
        if ($campaign->status === 'active' && $data['status'] !== 'active' && Campaign::where('status', 'active')->whereKeyNot($campaign->id)->doesntExist()) {
            throw ValidationException::withMessages(['status' => 'La plataforma debe conservar al menos una campaña activa.']);
        }

        $old = $this->auditValues($campaign);
        $campaign->update([
            'name' => $data['name'],
            'slug' => $slug,
            'candidate_name' => $data['candidate_name'],
            'office' => $data['office'],
            'territory' => $data['territory'],
            'starts_at' => $data['starts_at'] ?: null,
            'election_at' => $data['election_at'] ?: null,
            'status' => $data['status'],
            'timezone' => $data['timezone'],
            'theme_color' => strtoupper($data['theme_color']),
            'enabled_modules' => array_values(array_unique($data['enabled_modules'])),
        ]);
        Audit::record('campaign.updated', $campaign, $this->auditValues($campaign), $old, $campaign);

        return back()->with('success', 'La campaña y su identidad visual fueron actualizadas.');
    }

    public function destroy(Request $request, int $campaignId, CurrentCampaign $current): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $campaign = Campaign::withCount(['persons', 'meetings'])->findOrFail($campaignId);
        if ($campaign->id === $current->campaign->id) {
            throw ValidationException::withMessages(['campaign' => 'No puedes eliminar la campaña que estás gestionando.']);
        }
        if ($campaign->status === 'active' && Campaign::where('status', 'active')->whereKeyNot($campaign->id)->doesntExist()) {
            throw ValidationException::withMessages(['campaign' => 'La plataforma debe conservar al menos una campaña activa.']);
        }
        if ($request->string('confirmation')->toString() !== $campaign->candidate_name) {
            throw ValidationException::withMessages(['campaign' => 'La confirmación no coincide con el nombre del candidato.']);
        }

        Audit::record('campaign.deleted', $campaign, [
            ...$this->auditValues($campaign),
            'persons_count' => $campaign->persons_count,
            'meetings_count' => $campaign->meetings_count,
        ], campaign: $campaign);
        $campaign->delete();

        return back()->with('success', 'La campaña fue eliminada junto con sus datos compartimentados.');
    }

    private function validatedData(Request $request, CurrentCampaign $current, ?Campaign $campaign = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160'],
            'candidate_name' => ['required', 'string', 'max:180'],
            'office' => ['required', 'string', 'max:180'],
            'territory' => ['required', 'string', 'max:180'],
            'starts_at' => ['nullable', 'date'],
            'election_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'timezone' => ['required', 'timezone'],
            'theme_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'enabled_modules' => ['present', 'array'],
            'enabled_modules.*' => ['string', Rule::in(['territorial', 'meetings', 'inventory', 'analytics', 'calendar'])],
        ]);
    }

    private function ensureUniqueSlug(int $organizationId, string $slug, ?Campaign $campaign = null): void
    {
        $exists = Campaign::where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->when($campaign, fn ($query) => $query->whereKeyNot($campaign->id))
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['slug' => 'Ya existe una campaña con este identificador.']);
        }
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->is_super_admin, 403);
    }

    private function serialize(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'organization' => $campaign->organization->name,
            'name' => $campaign->name,
            'slug' => $campaign->slug,
            'candidateName' => $campaign->candidate_name,
            'office' => $campaign->office,
            'territory' => $campaign->territory,
            'startsAt' => $campaign->starts_at?->format('Y-m-d'),
            'electionAt' => $campaign->election_at?->format('Y-m-d'),
            'status' => $campaign->status,
            'timezone' => $campaign->timezone,
            'themeColor' => $campaign->theme_color,
            'enabledModules' => $campaign->enabled_modules ?? [],
            'membershipsCount' => $campaign->memberships_count,
            'personsCount' => $campaign->persons_count,
            'meetingsCount' => $campaign->meetings_count,
        ];
    }

    private function auditValues(Campaign $campaign): array
    {
        return $campaign->only([
            'name', 'slug', 'candidate_name', 'office', 'territory', 'starts_at',
            'election_at', 'status', 'timezone', 'theme_color', 'enabled_modules',
        ]);
    }
}
