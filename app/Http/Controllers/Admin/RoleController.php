<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignRole;
use App\Support\Audit;
use App\Support\CurrentCampaign;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(CurrentCampaign $current): Response
    {
        $current->authorize('roles.view');

        return Inertia::render('Admin/Roles/Index', [
            'roles' => CampaignRole::where('campaign_id', $current->campaign->id)
                ->withCount(['memberships', 'memberships as active_memberships_count' => fn ($query) => $query->where('is_active', true)])
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get()
                ->map(fn (CampaignRole $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'permissions' => $role->permissions,
                    'assignmentLevel' => $role->assignment_level,
                    'isSystem' => (bool) $role->is_system,
                    'membershipsCount' => $role->memberships_count,
                    'activeMembershipsCount' => $role->active_memberships_count,
                ]),
            'permissionGroups' => PermissionCatalog::groups(),
            'canGrantAll' => $current->membership->can('*'),
            'canManageDefinitions' => (bool) $current->membership->user?->is_super_admin,
        ]);
    }

    public function store(Request $request, CurrentCampaign $current): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $data = $this->validatedData($request, $current);
        $slug = Str::slug($data['slug'] ?: $data['name']);

        if (CampaignRole::where('campaign_id', $current->campaign->id)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'Ya existe un rol con este identificador.']);
        }

        $role = CampaignRole::create([
            'campaign_id' => $current->campaign->id,
            'name' => $data['name'],
            'slug' => $slug,
            'permissions' => array_values(array_unique($data['permissions'])),
            'assignment_level' => $data['assignment_level'],
        ]);
        Audit::record('role.created', $role, $role->only(['name', 'slug', 'permissions', 'assignment_level']), campaign: $current->campaign);

        return back()->with('success', 'El rol fue creado.');
    }

    public function update(Request $request, int $roleId, CurrentCampaign $current): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $role = $this->findRole($roleId, $current);
        $data = $this->validatedData($request, $current, $role);
        $slug = Str::slug($data['slug'] ?: $data['name']);
        if (CampaignRole::where('campaign_id', $current->campaign->id)
            ->where('slug', $slug)
            ->where('id', '!=', $role->id)
            ->exists()) {
            throw ValidationException::withMessages(['slug' => 'Ya existe un rol con este identificador.']);
        }
        $old = $role->only(['name', 'slug', 'permissions', 'assignment_level']);

        $role->update([
            'name' => $data['name'],
            'slug' => $slug,
            'permissions' => array_values(array_unique($data['permissions'])),
            'assignment_level' => $data['assignment_level'],
        ]);
        Audit::record('role.updated', $role, $role->only(['name', 'slug', 'permissions', 'assignment_level']), $old, $current->campaign);

        return back()->with('success', 'El rol y sus permisos fueron actualizados.');
    }

    public function destroy(Request $request, int $roleId, CurrentCampaign $current): RedirectResponse
    {
        $this->authorizeSuperAdmin($request);
        $role = $this->findRole($roleId, $current);
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'Los roles del sistema no se pueden eliminar.']);
        }
        if ($role->memberships()->exists()) {
            throw ValidationException::withMessages(['role' => 'Reasigna los usuarios de este rol antes de eliminarlo.']);
        }

        Audit::record('role.deleted', $role, ['deleted' => true], campaign: $current->campaign);
        $role->delete();

        return back()->with('success', 'El rol fue eliminado.');
    }

    private function validatedData(Request $request, CurrentCampaign $current, ?CampaignRole $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable', 'string', 'max:120',
                Rule::unique('campaign_roles', 'slug')
                    ->where('campaign_id', $current->campaign->id)
                    ->ignore($role?->id),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in([...PermissionCatalog::keys(), '*'])],
            'assignment_level' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->is_super_admin, 403);
    }

    private function findRole(int $id, CurrentCampaign $current): CampaignRole
    {
        return CampaignRole::where('campaign_id', $current->campaign->id)->findOrFail($id);
    }

}
