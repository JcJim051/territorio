<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\TerritoryUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAndRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_complete_user_and_role_crud_with_campaign_scope(): void
    {
        [$organization, $campaign, $admin] = $this->campaignWithUser(['*'], true);
        $territory = TerritoryUnit::create([
            'campaign_id' => $campaign->id,
            'type' => 'commune',
            'code' => 'C01',
            'name' => 'Comuna 1',
        ]);

        $this->actingAs($admin)->post('/admin/roles', [
            'name' => 'Coordinación territorial',
            'slug' => 'territorial',
            'permissions' => ['dashboard.view', 'territorial.view', 'territorial.manage'],
            'assignment_level' => 60,
        ])->assertRedirect();

        $role = CampaignRole::where('campaign_id', $campaign->id)->where('slug', 'territorial')->firstOrFail();
        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Coordinadora Uno',
            'email' => 'coordinadora@example.test',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
            'campaign_role_id' => $role->id,
            'territory_unit_ids' => [$territory->id],
            'is_active' => true,
            'account_active' => true,
            'is_super_admin' => false,
        ])->assertRedirect();

        $user = User::where('email', 'coordinadora@example.test')->firstOrFail();
        $membership = CampaignMembership::where('campaign_id', $campaign->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame([$territory->id], $membership->territorial_scope['territory_unit_ids']);

        $this->actingAs($admin)->put("/admin/users/{$membership->id}", [
            'name' => 'Coordinadora Actualizada',
            'email' => 'coordinadora@example.test',
            'password' => '',
            'password_confirmation' => '',
            'campaign_role_id' => $role->id,
            'territory_unit_ids' => [],
            'is_active' => false,
            'account_active' => false,
            'is_super_admin' => false,
        ])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Coordinadora Actualizada', 'is_active' => false]);
        $this->assertDatabaseHas('campaign_memberships', ['id' => $membership->id, 'is_active' => false]);

        $this->actingAs($admin)->delete("/admin/users/{$membership->id}")->assertRedirect();
        $this->assertDatabaseMissing('campaign_memberships', ['id' => $membership->id]);

        $this->actingAs($admin)->put("/admin/roles/{$role->id}", [
            'name' => 'Coordinación actualizada',
            'slug' => 'territorial',
            'permissions' => ['territorial.view'],
            'assignment_level' => 60,
        ])->assertRedirect();
        $this->actingAs($admin)->delete("/admin/roles/{$role->id}")->assertRedirect();
        $this->assertDatabaseMissing('campaign_roles', ['id' => $role->id]);
    }

    public function test_user_management_rejects_cross_campaign_role_and_territory(): void
    {
        [$organization, $campaign, $admin] = $this->campaignWithUser(['*'], true);
        $otherCampaign = $this->campaign($organization, 'otra');
        $otherRole = CampaignRole::create([
            'campaign_id' => $otherCampaign->id,
            'name' => 'Otro rol',
            'slug' => 'otro',
            'permissions' => ['dashboard.view'],
        ]);
        $otherTerritory = TerritoryUnit::create([
            'campaign_id' => $otherCampaign->id,
            'type' => 'commune',
            'code' => 'C99',
            'name' => 'Comuna externa',
        ]);

        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Usuario externo',
            'email' => 'externo@example.test',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
            'campaign_role_id' => $otherRole->id,
            'territory_unit_ids' => [$otherTerritory->id],
            'is_active' => true,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'externo@example.test']);
    }

    public function test_limited_manager_cannot_escalate_permissions_or_modify_super_admin(): void
    {
        [$organization, $campaign, $superAdmin] = $this->campaignWithUser(['*'], true);
        $limitedRole = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Gestor limitado',
            'slug' => 'limited',
            'permissions' => ['users.view', 'users.manage', 'roles.view', 'roles.manage'],
            'assignment_level' => 80,
        ]);
        $manager = User::factory()->create();
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $manager->id,
            'campaign_role_id' => $limitedRole->id,
        ]);
        $superMembership = CampaignMembership::where('campaign_id', $campaign->id)->where('user_id', $superAdmin->id)->firstOrFail();

        $this->actingAs($manager)->post('/admin/roles', [
            'name' => 'Rol escalado',
            'slug' => 'escalado',
            'permissions' => ['inventory.manage'],
            'assignment_level' => 20,
        ])->assertForbidden();

        $this->actingAs($manager)->put("/admin/users/{$superMembership->id}", [
            'name' => $superAdmin->name,
            'email' => $superAdmin->email,
            'campaign_role_id' => $superMembership->campaign_role_id,
            'territory_unit_ids' => [],
            'is_active' => true,
        ])->assertForbidden();
    }

    public function test_last_campaign_administrator_cannot_be_removed(): void
    {
        [$organization, $campaign, $administrator] = $this->campaignWithUser(['*']);
        $operatorRole = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Operador de usuarios',
            'slug' => 'user-operator',
            'permissions' => ['users.view', 'users.delete', 'roles.manage'],
        ]);
        $operator = User::factory()->create();
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $operator->id,
            'campaign_role_id' => $operatorRole->id,
        ]);
        $adminMembership = CampaignMembership::where('campaign_id', $campaign->id)->where('user_id', $administrator->id)->firstOrFail();

        $this->actingAs($operator)
            ->delete("/admin/users/{$adminMembership->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('campaign_memberships', ['id' => $adminMembership->id]);

        $this->actingAs($operator)->put("/admin/roles/{$adminMembership->campaign_role_id}", [
            'name' => 'Administrador reducido',
            'slug' => 'initial',
            'permissions' => [],
            'assignment_level' => 10,
        ])->assertForbidden();
    }

    public function test_territorial_scope_is_enforced_on_operational_queries_and_mutations(): void
    {
        [$organization, $campaign, $user] = $this->campaignWithUser(['meetings.view', 'meetings.manage']);
        $allowed = TerritoryUnit::create(['campaign_id' => $campaign->id, 'type' => 'commune', 'code' => 'C01', 'name' => 'Comuna 1']);
        $blocked = TerritoryUnit::create(['campaign_id' => $campaign->id, 'type' => 'commune', 'code' => 'C02', 'name' => 'Comuna 2']);
        $membership = CampaignMembership::where('campaign_id', $campaign->id)->where('user_id', $user->id)->firstOrFail();
        $membership->update(['territorial_scope' => ['territory_unit_ids' => [$allowed->id]]]);
        $allowedMeeting = $this->meeting($campaign, $user, $allowed, 'Permitida');
        $blockedMeeting = $this->meeting($campaign, $user, $blocked, 'Restringida');

        $this->actingAs($user)->get('/meetings')
            ->assertInertia(fn ($page) => $page
                ->has('meetings', 1)
                ->where('meetings.0.id', $allowedMeeting->public_id));

        $this->actingAs($user)->put("/meetings/{$blockedMeeting->public_id}", [
            'title' => 'Intento',
            'type' => 'reunion',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'expected_attendees' => 10,
            'territory_unit_id' => $blocked->id,
        ])->assertNotFound();
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    private function campaignWithUser(array $permissions, bool $superAdmin = false): array
    {
        $organization = Organization::create(['name' => 'Organización', 'slug' => 'org-'.str()->random(6)]);
        $campaign = $this->campaign($organization, 'principal');
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Rol inicial',
            'slug' => 'initial',
            'permissions' => $permissions,
        ]);
        $user = User::factory()->create(['is_super_admin' => $superAdmin]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'campaign_role_id' => $role->id,
        ]);

        return [$organization, $campaign, $user];
    }

    private function campaign(Organization $organization, string $slug): Campaign
    {
        return Campaign::create([
            'organization_id' => $organization->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'candidate_name' => 'Candidato',
            'office' => 'Concejo',
            'territory' => 'Meta',
        ]);
    }

    private function meeting(Campaign $campaign, User $user, TerritoryUnit $territory, string $title): Meeting
    {
        return Meeting::create([
            'public_id' => (string) str()->ulid(),
            'campaign_id' => $campaign->id,
            'requested_by' => $user->id,
            'territory_unit_id' => $territory->id,
            'type' => 'reunion',
            'title' => $title,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'requested',
        ]);
    }
}
