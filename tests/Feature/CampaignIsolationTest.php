<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_switch_to_a_campaign_without_membership(): void
    {
        $organization = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $allowed = $this->campaign($organization->id, 'allowed');
        $blocked = $this->campaign($organization->id, 'blocked');
        $user = User::factory()->create();
        $role = CampaignRole::create([
            'campaign_id' => $allowed->id,
            'name' => 'Gerencia',
            'slug' => 'manager',
            'permissions' => ['*'],
        ]);
        CampaignMembership::create([
            'campaign_id' => $allowed->id,
            'user_id' => $user->id,
            'campaign_role_id' => $role->id,
        ]);

        $this->actingAs($user)
            ->post('/campaign/switch', ['campaign_id' => $blocked->id])
            ->assertForbidden();
    }

    public function test_dashboard_resolves_only_an_authorized_campaign(): void
    {
        $organization = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $campaign = $this->campaign($organization->id, 'active');
        $user = User::factory()->create();
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Analista',
            'slug' => 'analyst',
            'permissions' => ['dashboard.view'],
        ]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'campaign_role_id' => $role->id,
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('currentCampaign.id', $campaign->id));
    }

    public function test_regular_user_cannot_switch_even_when_legacy_memberships_exist(): void
    {
        $organization = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $first = $this->campaign($organization->id, 'first');
        $second = $this->campaign($organization->id, 'second');
        $user = User::factory()->create(['is_super_admin' => false]);
        foreach ([$first, $second] as $campaign) {
            $role = CampaignRole::create([
                'campaign_id' => $campaign->id,
                'name' => 'Operación',
                'slug' => 'operation',
                'permissions' => ['dashboard.view'],
            ]);
            CampaignMembership::create([
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'campaign_role_id' => $role->id,
            ]);
        }

        $this->actingAs($user)
            ->post('/campaign/switch', ['campaign_id' => $second->id])
            ->assertForbidden();

        $this->actingAs($user)->get('/')
            ->assertInertia(fn ($page) => $page
                ->has('campaigns', 1)
                ->where('campaigns.0.id', $first->id)
                ->where('currentCampaign.id', $first->id)
                ->where('currentCampaign.isSuperAdmin', false));
    }

    public function test_super_admin_can_switch_to_any_active_campaign_and_gets_a_membership(): void
    {
        $organization = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $first = $this->campaign($organization->id, 'first');
        $second = $this->campaign($organization->id, 'second');
        $admin = User::factory()->create(['is_super_admin' => true]);
        CampaignMembership::create([
            'campaign_id' => $first->id,
            'user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from('/')
            ->post('/campaign/switch', ['campaign_id' => $second->id])
            ->assertRedirect('/');

        $this->assertDatabaseHas('campaign_memberships', [
            'campaign_id' => $second->id,
            'user_id' => $admin->id,
            'is_active' => true,
        ]);
        $this->assertSame($second->id, session('campaign_id'));

        $this->get('/')
            ->assertInertia(fn ($page) => $page
                ->has('campaigns', 2)
                ->where('currentCampaign.id', $second->id)
                ->where('currentCampaign.isSuperAdmin', true));
    }

    private function campaign(int $organizationId, string $slug): Campaign
    {
        return Campaign::create([
            'organization_id' => $organizationId,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'candidate_name' => 'Candidato',
            'office' => 'Concejo',
            'territory' => 'Meta',
        ]);
    }
}
