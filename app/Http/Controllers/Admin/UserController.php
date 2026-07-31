<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\TerritoryUnit;
use App\Models\User;
use App\Support\Audit;
use App\Support\CampaignRoleAssignmentPolicy;
use App\Support\CurrentCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request, CurrentCampaign $current, CampaignRoleAssignmentPolicy $policy): Response
    {
        $current->authorize('users.view');
        $search = $request->string('search')->trim()->toString();
        $roleId = $request->integer('role');
        $status = $request->string('status')->toString();

        $memberships = CampaignMembership::query()
            ->with(['user:id,name,email,is_super_admin,is_active,created_at', 'role:id,name,permissions,assignment_level'])
            ->where('campaign_id', $current->campaign->id)
            ->when($search, fn ($query) => $query->whereHas('user', fn ($user) => $user
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($roleId, fn ($query) => $query->where('campaign_role_id', $roleId))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true)->whereHas('user', fn ($user) => $user->where('is_active', true)))
            ->when($status === 'inactive', fn ($query) => $query->where(fn ($membership) => $membership
                ->where('is_active', false)
                ->orWhereHas('user', fn ($user) => $user->where('is_active', false))))
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CampaignMembership $membership) => [
                ...$this->serialize($membership),
                'manageable' => $policy->canManage($current->membership, $membership),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'memberships' => $memberships,
            'roles' => CampaignRole::where('campaign_id', $current->campaign->id)
                ->withCount('memberships')
                ->orderBy('name')
                ->get(['id', 'name', 'permissions', 'assignment_level'])
                ->map(fn (CampaignRole $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions,
                    'assignment_level' => $role->assignment_level,
                    'memberships_count' => $role->memberships_count,
                    'assignable' => $policy->canAssign($current->membership, $role),
                ]),
            'territories' => TerritoryUnit::where('campaign_id', $current->campaign->id)
                ->whereIn('type', ['commune', 'district', 'neighborhood', 'rural'])
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
            'filters' => ['search' => $search, 'role' => $roleId ?: null, 'status' => $status],
            'capabilities' => [
                'canPromoteSuperAdmin' => (bool) $current->membership->user?->is_super_admin,
            ],
        ]);
    }

    public function store(Request $request, CurrentCampaign $current, CampaignRoleAssignmentPolicy $policy): RedirectResponse
    {
        $current->authorize('users.manage');
        $data = $this->validatedData($request, $current, creating: true);
        $policy->authorizeAssignment($current->membership, CampaignRole::findOrFail($data['campaign_role_id']));

        DB::transaction(function () use ($data, $request, $current) {
            $user = User::where('email', mb_strtolower($data['email']))->first();
            if ($user) {
                $belongsToOrganization = $user->campaignMemberships()
                    ->whereHas('campaign', fn ($campaign) => $campaign->where('organization_id', $current->campaign->organization_id))
                    ->exists();
                if (! $belongsToOrganization) {
                    throw ValidationException::withMessages(['email' => 'El correo ya pertenece a una cuenta de otra organización.']);
                }
            } else {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => mb_strtolower($data['email']),
                    'password' => $data['password'],
                    'email_verified_at' => now(),
                    'is_super_admin' => $this->requestedSuperAdmin($request, $current),
                    'is_active' => true,
                ]);
            }

            if (CampaignMembership::where('campaign_id', $current->campaign->id)->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['email' => 'Este usuario ya pertenece a la campaña activa.']);
            }

            $membership = CampaignMembership::create([
                'campaign_id' => $current->campaign->id,
                'user_id' => $user->id,
                'campaign_role_id' => $data['campaign_role_id'],
                'territorial_scope' => $this->scope($data),
                'is_active' => $data['is_active'],
            ]);

            Audit::record('user.membership_created', $membership, [
                'user_id' => $user->id,
                'role_id' => $membership->campaign_role_id,
                'territorial_scope' => $membership->territorial_scope,
            ], campaign: $current->campaign);
        });

        return back()->with('success', 'El usuario fue creado y asignado a la campaña.');
    }

    public function update(Request $request, int $membershipId, CurrentCampaign $current, CampaignRoleAssignmentPolicy $policy): RedirectResponse
    {
        $current->authorize('users.manage');
        $membership = $this->findMembership($membershipId, $current);
        $policy->authorizeTarget($current->membership, $membership);
        $this->protectTarget($membership, $request, $current);
        $data = $this->validatedData($request, $current, $membership);
        $newRole = CampaignRole::where('campaign_id', $current->campaign->id)->findOrFail($data['campaign_role_id']);
        $policy->authorizeAssignment($current->membership, $newRole);
        $willRemainAdministrator = $this->requestedSuperAdmin($request, $current, $membership->user)
            || in_array('*', $newRole->permissions ?? [], true);

        if ($this->isAdministrator($membership) && (! $data['is_active'] || ! $willRemainAdministrator)) {
            $this->ensureAnotherAdministrator($membership, $current);
        }

        DB::transaction(function () use ($data, $request, $current, $membership) {
            $old = [
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role_id' => $membership->campaign_role_id,
                'scope' => $membership->territorial_scope,
                'membership_active' => $membership->is_active,
                'account_active' => $membership->user->is_active,
                'is_super_admin' => $membership->user->is_super_admin,
            ];

            $userChanges = [
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
            ];
            if (! empty($data['password'])) {
                $userChanges['password'] = $data['password'];
            }
            if ($current->membership->user->is_super_admin) {
                $userChanges['is_super_admin'] = $request->boolean('is_super_admin');
                $userChanges['is_active'] = $request->boolean('account_active');
            }
            $membership->user->update($userChanges);
            if (array_key_exists('is_active', $userChanges) && ! $userChanges['is_active']) {
                DB::table('sessions')->where('user_id', $membership->user_id)->delete();
            }
            $membership->update([
                'campaign_role_id' => $data['campaign_role_id'],
                'territorial_scope' => $this->scope($data),
                'is_active' => $data['is_active'],
            ]);

            Audit::record('user.membership_updated', $membership, [
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role_id' => $membership->campaign_role_id,
                'scope' => $membership->territorial_scope,
                'membership_active' => $membership->is_active,
                'account_active' => $membership->user->is_active,
                'is_super_admin' => $membership->user->is_super_admin,
            ], $old, $current->campaign);
        });

        return back()->with('success', 'El usuario y su acceso fueron actualizados.');
    }

    public function destroy(Request $request, int $membershipId, CurrentCampaign $current, CampaignRoleAssignmentPolicy $policy): RedirectResponse
    {
        $current->authorize('users.delete');
        $membership = $this->findMembership($membershipId, $current);
        $policy->authorizeTarget($current->membership, $membership);
        $this->protectTarget($membership, $request, $current, deleting: true);
        if ($this->isAdministrator($membership)) {
            $this->ensureAnotherAdministrator($membership, $current);
        }

        Audit::record('user.membership_removed', $membership, [
            'user_id' => $membership->user_id,
            'role_id' => $membership->campaign_role_id,
        ], campaign: $current->campaign);
        $membership->delete();

        return back()->with('success', 'El acceso del usuario a esta campaña fue retirado.');
    }

    private function validatedData(Request $request, CurrentCampaign $current, ?CampaignMembership $membership = null, bool $creating = false): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'email' => [
                'required', 'email', 'max:180',
                $membership ? Rule::unique('users', 'email')->ignore($membership->user_id) : Rule::unique('users', 'email')->where(fn ($query) => $query->whereRaw('1 = 0')),
            ],
            'password' => [$creating ? 'required' : 'nullable', 'nullable', 'string', 'min:8', 'confirmed'],
            'campaign_role_id' => ['required', 'integer'],
            'territory_unit_ids' => ['array'],
            'territory_unit_ids.*' => ['integer'],
            'is_active' => ['required', 'boolean'],
            'account_active' => ['sometimes', 'boolean'],
            'is_super_admin' => ['sometimes', 'boolean'],
        ]);

        abort_unless(CampaignRole::where('campaign_id', $current->campaign->id)->whereKey($data['campaign_role_id'])->exists(), 422);
        $territoryIds = collect($data['territory_unit_ids'] ?? [])->unique()->values();
        $validTerritories = TerritoryUnit::where('campaign_id', $current->campaign->id)->whereIn('id', $territoryIds)->count();
        if ($validTerritories !== $territoryIds->count()) {
            throw ValidationException::withMessages(['territory_unit_ids' => 'Uno de los territorios no pertenece a la campaña activa.']);
        }

        return $data;
    }

    private function scope(array $data): ?array
    {
        $ids = collect($data['territory_unit_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

        return $ids ? ['territory_unit_ids' => $ids] : null;
    }

    private function requestedSuperAdmin(Request $request, CurrentCampaign $current, ?User $existing = null): bool
    {
        return $current->membership->user->is_super_admin
            ? $request->boolean('is_super_admin')
            : (bool) $existing?->is_super_admin;
    }

    private function findMembership(int $id, CurrentCampaign $current): CampaignMembership
    {
        return CampaignMembership::with(['user', 'role'])
            ->where('campaign_id', $current->campaign->id)
            ->findOrFail($id);
    }

    private function protectTarget(CampaignMembership $target, Request $request, CurrentCampaign $current, bool $deleting = false): void
    {
        if ($target->user->is_super_admin && ! $current->membership->user->is_super_admin) {
            abort(403, 'Solo un superadministrador puede modificar esta cuenta.');
        }
        if ($target->user_id === $request->user()->id) {
            if ($deleting
                || ! $request->boolean('is_active')
                || ($current->membership->user->is_super_admin && (! $request->boolean('account_active') || ! $request->boolean('is_super_admin')))) {
                throw ValidationException::withMessages(['user' => 'No puedes retirar o desactivar tu propio acceso.']);
            }
        }
    }

    private function isAdministrator(CampaignMembership $membership): bool
    {
        return $membership->user->is_super_admin
            || ($membership->role?->assignment_level ?? 0) >= 100
            || in_array('*', $membership->role?->permissions ?? [], true);
    }

    private function ensureAnotherAdministrator(CampaignMembership $target, CurrentCampaign $current): void
    {
        $exists = CampaignMembership::with(['user', 'role'])
            ->where('campaign_id', $current->campaign->id)
            ->where('is_active', true)
            ->where('id', '!=', $target->id)
            ->get()
            ->contains(fn (CampaignMembership $membership) => $membership->user->is_active && $this->isAdministrator($membership));

        if (! $exists) {
            throw ValidationException::withMessages(['user' => 'La campaña debe conservar al menos un administrador activo.']);
        }
    }

    private function serialize(CampaignMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'userId' => $membership->user_id,
            'name' => $membership->user->name,
            'email' => $membership->user->email,
            'roleId' => $membership->campaign_role_id,
            'role' => $membership->role?->name,
            'assignmentLevel' => $membership->role?->assignment_level ?? 0,
            'isAdministrator' => $this->isAdministrator($membership),
            'isSuperAdmin' => (bool) $membership->user->is_super_admin,
            'accountActive' => (bool) $membership->user->is_active,
            'membershipActive' => (bool) $membership->is_active,
            'territoryIds' => $membership->territorial_scope['territory_unit_ids'] ?? [],
            'lastAccessedAt' => $membership->last_accessed_at?->toIso8601String(),
            'createdAt' => $membership->created_at->toIso8601String(),
        ];
    }
}
