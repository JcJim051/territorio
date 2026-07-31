<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit;
use App\Support\OfficialRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficialCampaignRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioner_creates_nine_roles_idempotently_and_preserves_legacy_membership(): void
    {
        [$campaign, $user] = $this->context();
        $legacy = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Gerencia',
            'slug' => 'administrator',
            'permissions' => ['*'],
        ]);
        $membership = CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'campaign_role_id' => $legacy->id,
        ]);

        $provisioner = app(OfficialRoleProvisioner::class);
        $provisioner->provision($campaign);
        $provisioner->provision($campaign);

        $this->assertSame(9, CampaignRole::where('campaign_id', $campaign->id)->where('is_system', true)->count());
        $this->assertSame('technical-administrator', $membership->fresh()->role->slug);
        $this->assertSame(100, $membership->fresh()->role->assignment_level);
        $this->assertNotContains('*', $membership->fresh()->role->permissions);
    }

    public function test_technical_administrator_and_manager_obey_assignment_hierarchy(): void
    {
        [$campaign, $super] = $this->context(super: true);
        $roles = app(OfficialRoleProvisioner::class)->provision($campaign);
        CampaignMembership::create(['campaign_id' => $campaign->id, 'user_id' => $super->id, 'campaign_role_id' => $roles['technical-administrator']->id]);
        $technical = User::factory()->create();
        CampaignMembership::create(['campaign_id' => $campaign->id, 'user_id' => $technical->id, 'campaign_role_id' => $roles['technical-administrator']->id]);

        $payload = [
            'name' => 'Nuevo gerente', 'email' => 'gerente@example.test',
            'password' => 'password-segura', 'password_confirmation' => 'password-segura',
            'campaign_role_id' => $roles['manager']->id, 'territory_unit_ids' => [], 'is_active' => true,
        ];
        $this->actingAs($technical)->post('/admin/users', $payload)->assertRedirect();
        $this->actingAs($technical)->post('/admin/users', [
            ...$payload, 'email' => 'tecnico2@example.test', 'campaign_role_id' => $roles['technical-administrator']->id,
        ])->assertSessionHasErrors('campaign_role_id');

        $manager = User::where('email', 'gerente@example.test')->firstOrFail();
        $this->actingAs($manager)->post('/admin/users', [
            ...$payload, 'email' => 'auditor@example.test', 'campaign_role_id' => $roles['auditor']->id,
        ])->assertSessionHasErrors('campaign_role_id');
        $this->actingAs($manager)->post('/admin/users', [
            ...$payload, 'email' => 'conductor@example.test', 'campaign_role_id' => $roles['driver']->id,
        ])->assertRedirect();
        $this->actingAs($technical)->post('/admin/roles', [
            'name' => 'No autorizado', 'slug' => 'no-autorizado', 'permissions' => [], 'assignment_level' => 10,
        ])->assertForbidden();
    }

    public function test_driver_only_sees_approved_routes_in_campaign_horizon(): void
    {
        [$campaign, $driver] = $this->context();
        $campaign->update(['settings' => ['driver_agenda_days' => 3]]);
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id, 'name' => 'Conductor', 'slug' => 'driver',
            'permissions' => ['driver.routes.view'], 'assignment_level' => 20,
        ]);
        CampaignMembership::create(['campaign_id' => $campaign->id, 'user_id' => $driver->id, 'campaign_role_id' => $role->id]);
        $approved = $this->meeting($campaign, 'approved', now()->addDay());
        $this->meeting($campaign, 'requested', now()->addDay()->addHour());
        $this->meeting($campaign, 'approved', now()->addDays(5));

        $this->actingAs($driver)->get('/driver/routes')
            ->assertInertia(fn ($page) => $page
                ->component('Driver/Routes')
                ->where('days', 3)
                ->where('nextMeetingId', $approved->public_id)
                ->has('dates', 1));
        $this->actingAs($driver)->get('/meetings')->assertForbidden();
    }

    public function test_operational_settings_and_audit_are_isolated_by_campaign(): void
    {
        [$campaign, $admin] = $this->context();
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id, 'name' => 'Gerente', 'slug' => 'manager',
            'permissions' => ['campaign.settings.manage', 'audit.view', 'audit.export'], 'assignment_level' => 80,
        ]);
        CampaignMembership::create(['campaign_id' => $campaign->id, 'user_id' => $admin->id, 'campaign_role_id' => $role->id]);
        $other = Campaign::create([
            'organization_id' => $campaign->organization_id, 'name' => 'Otra', 'slug' => 'otra',
            'candidate_name' => 'Otra', 'office' => 'Concejo', 'territory' => 'Acacías',
            'status' => 'active', 'timezone' => 'America/Bogota', 'settings' => ['driver_agenda_days' => 7],
        ]);
        Audit::record('meeting.created', campaign: $campaign, newValues: ['title' => 'Visible', 'access_token' => 'secret']);
        Audit::record('meeting.created', campaign: $other, newValues: ['title' => 'Oculta']);

        $this->actingAs($admin)->put('/campaign/settings/operations', ['driver_agenda_days' => 12])->assertRedirect();
        $this->assertSame(12, $campaign->fresh()->settings['driver_agenda_days']);
        $this->assertSame(7, $other->fresh()->settings['driver_agenda_days']);
        $this->actingAs($admin)->get('/admin/audit?event=meeting.created')
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Audit/Index')
                ->where('events.data.0.newValues.access_token', '[PROTEGIDO]')
                ->where('events.data.0.newValues.title', 'Visible'));
    }

    private function context(bool $super = false): array
    {
        $organization = Organization::create(['name' => 'Organización', 'slug' => 'org-'.Str::lower(Str::random(6))]);
        $campaign = Campaign::create([
            'organization_id' => $organization->id, 'name' => 'Campaña', 'slug' => 'camp-'.Str::lower(Str::random(6)),
            'candidate_name' => 'Candidato', 'office' => 'Concejo', 'territory' => 'Villavicencio',
            'status' => 'active', 'timezone' => 'America/Bogota', 'settings' => ['driver_agenda_days' => 7],
        ]);

        return [$campaign, User::factory()->create(['is_super_admin' => $super])];
    }

    private function meeting(Campaign $campaign, string $status, $starts): Meeting
    {
        return Meeting::create([
            'public_id' => (string) Str::ulid(), 'campaign_id' => $campaign->id,
            'type' => 'reunion', 'title' => 'Información política reservada',
            'location' => 'Casa comunal', 'address' => 'Calle 10 # 20-30',
            'latitude' => 4.142, 'longitude' => -73.626, 'location_notes' => 'Entrada lateral',
            'starts_at' => $starts, 'ends_at' => $starts->copy()->addHour(), 'status' => $status,
        ]);
    }
}
