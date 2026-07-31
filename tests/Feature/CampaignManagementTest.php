<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_administrators_can_manage_campaigns(): void
    {
        [$organization, $campaign] = $this->campaignContext();
        $user = User::factory()->create(['is_super_admin' => false]);
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Gerencia',
            'slug' => 'manager',
            'permissions' => ['campaigns.manage'],
        ]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'campaign_role_id' => $role->id,
        ]);

        $this->actingAs($user)->get('/admin/campaigns')->assertForbidden();
        $this->actingAs($user)->post('/admin/campaigns', $this->payload())->assertForbidden();
    }

    public function test_super_administrator_can_create_a_compartmentalized_campaign_with_its_theme(): void
    {
        [$organization, $campaign, $admin] = $this->superAdminContext();

        $this->actingAs($admin)
            ->post('/admin/campaigns', $this->payload([
                'name' => 'Acacías 2027',
                'slug' => 'acacias-2027',
                'candidate_name' => 'Candidata Acacías',
                'theme_color' => '#6D28D9',
            ]))
            ->assertRedirect();

        $created = Campaign::where('slug', 'acacias-2027')->firstOrFail();
        $this->assertSame($organization->id, $created->organization_id);
        $this->assertSame('#6D28D9', $created->theme_color);
        $this->assertDatabaseHas('campaign_roles', [
            'campaign_id' => $created->id,
            'slug' => 'technical-administrator',
            'is_system' => true,
        ]);
        $this->assertSame(9, CampaignRole::where('campaign_id', $created->id)->where('is_system', true)->count());
        $this->assertDatabaseHas('campaign_memberships', [
            'campaign_id' => $created->id,
            'user_id' => $admin->id,
            'is_active' => true,
        ]);
        $this->assertSame(1, CampaignMembership::where('campaign_id', $created->id)->count());
    }

    public function test_updating_one_campaign_color_does_not_change_another_campaign(): void
    {
        [$organization, $first, $admin] = $this->superAdminContext();
        $second = $this->campaign($organization, 'acacias', '#2563EB');
        CampaignMembership::create(['campaign_id' => $second->id, 'user_id' => $admin->id]);

        $this->actingAs($admin)
            ->put("/admin/campaigns/{$second->id}", $this->payload([
                'name' => $second->name,
                'slug' => $second->slug,
                'candidate_name' => $second->candidate_name,
                'theme_color' => '#BE123C',
            ]))
            ->assertRedirect();

        $this->assertSame('#0D4D4B', $first->fresh()->theme_color);
        $this->assertSame('#BE123C', $second->fresh()->theme_color);

        $this->actingAs($admin)
            ->post('/campaign/switch', ['campaign_id' => $second->id])
            ->assertRedirect();
        $this->get('/')
            ->assertInertia(fn ($page) => $page
                ->where('currentCampaign.id', $second->id)
                ->where('currentCampaign.themeColor', '#BE123C')
                ->where('campaigns', fn ($campaigns) => $campaigns
                    ->contains(fn ($campaign) => $campaign['id'] === $second->id && $campaign['themeColor'] === '#BE123C')));
    }

    public function test_campaign_color_must_be_a_complete_hexadecimal_value(): void
    {
        [, , $admin] = $this->superAdminContext();

        $this->actingAs($admin)
            ->post('/admin/campaigns', $this->payload(['theme_color' => 'red']))
            ->assertSessionHasErrors('theme_color');
    }

    public function test_current_campaign_cannot_be_deleted_or_deactivated(): void
    {
        [, $campaign, $admin] = $this->superAdminContext();

        $this->actingAs($admin)
            ->put("/admin/campaigns/{$campaign->id}", $this->payload([
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'candidate_name' => $campaign->candidate_name,
                'status' => 'inactive',
            ]))
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)
            ->delete("/admin/campaigns/{$campaign->id}", ['confirmation' => $campaign->candidate_name])
            ->assertSessionHasErrors('campaign');

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }

    public function test_users_cannot_enter_an_inactive_campaign(): void
    {
        [, $campaign] = $this->campaignContext();
        $user = User::factory()->create();
        CampaignMembership::create(['campaign_id' => $campaign->id, 'user_id' => $user->id]);
        $campaign->update(['status' => 'inactive']);

        $this->actingAs($user)->get('/')->assertForbidden();
    }

    private function superAdminContext(): array
    {
        [$organization, $campaign] = $this->campaignContext();
        $admin = User::factory()->create(['is_super_admin' => true]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $admin->id,
        ]);

        return [$organization, $campaign, $admin];
    }

    private function campaignContext(): array
    {
        $organization = Organization::create(['name' => 'Organización', 'slug' => 'organizacion']);

        return [$organization, $this->campaign($organization, 'villavicencio', '#0D4D4B')];
    }

    private function campaign(Organization $organization, string $slug, string $color): Campaign
    {
        return Campaign::create([
            'organization_id' => $organization->id,
            'name' => ucfirst($slug).' 2027',
            'slug' => $slug,
            'candidate_name' => 'Candidato '.ucfirst($slug),
            'office' => 'Concejo municipal',
            'territory' => ucfirst($slug).', Meta',
            'status' => 'active',
            'timezone' => 'America/Bogota',
            'theme_color' => $color,
            'enabled_modules' => ['territorial', 'meetings'],
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Nueva campaña',
            'slug' => 'nueva-campana',
            'candidate_name' => 'Nuevo candidato',
            'office' => 'Concejo municipal',
            'territory' => 'Meta',
            'starts_at' => '2026-08-01',
            'election_at' => '2027-10-31',
            'status' => 'active',
            'timezone' => 'America/Bogota',
            'theme_color' => '#0D4D4B',
            'enabled_modules' => ['territorial', 'meetings', 'inventory', 'analytics', 'calendar'],
            ...$overrides,
        ];
    }
}
