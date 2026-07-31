<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCrudAndDecisionQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_bypasses_role_permissions_and_can_manage_visible_modules(): void
    {
        [$organization, $campaign, $admin] = $this->adminContext();
        $this->actingAs($admin);

        $this->post('/people', [
            'name' => 'Lider Territorial',
            'email' => 'lider@example.test',
            'phone' => '3001234567',
            'document_number' => '11223344',
            'status' => 'pending',
        ])->assertRedirect();

        $person = Person::where('campaign_id', $campaign->id)->firstOrFail();
        $this->put("/people/{$person->public_id}", [
            'name' => 'Líder Territorial Editado',
            'email' => 'lider@example.test',
            'phone' => '3001234567',
            'status' => 'pending',
        ])->assertRedirect();
        $this->post("/people/{$person->public_id}/verify", ['consent_confirmed' => true])->assertRedirect();
        $this->assertDatabaseHas('persons', ['id' => $person->id, 'status' => 'verified']);
        $this->assertDatabaseHas('consents', ['person_id' => $person->id, 'channel' => 'admin_attestation']);

        $this->post('/meetings', [
            'title' => 'Encuentro comunal',
            'type' => 'reunion',
            'location' => 'Salón comunal',
            'address' => 'Carrera 10 # 20-30',
            'latitude' => 4.1420,
            'longitude' => -73.6266,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'expected_attendees' => 30,
        ])->assertRedirect();

        $meeting = Meeting::where('campaign_id', $campaign->id)->firstOrFail();
        $this->put("/meetings/{$meeting->public_id}", [
            'title' => 'Encuentro comunal actualizado',
            'type' => 'reunion',
            'location' => 'Salón comunal',
            'address' => 'Carrera 10 # 20-30',
            'latitude' => 4.1420,
            'longitude' => -73.6266,
            'starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(2)->addHour()->format('Y-m-d H:i:s'),
            'expected_attendees' => 35,
        ])->assertRedirect();
        $this->post("/meetings/{$meeting->public_id}/approve")->assertRedirect();
        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'status' => 'approved']);

        $this->post('/inventory', [
            'name' => 'Volantes',
            'sku' => 'VOL-01',
            'kind' => 'consumable',
            'unit' => 'unidad',
            'quantity' => 100,
            'minimum_quantity' => 25,
            'is_shared' => false,
        ])->assertRedirect();

        $resource = Resource::where('organization_id', $organization->id)->firstOrFail();
        $this->put("/inventory/{$resource->id}", [
            'name' => 'Volantes campaña',
            'sku' => 'VOL-01',
            'kind' => 'consumable',
            'unit' => 'unidad',
            'minimum_quantity' => 30,
            'is_shared' => false,
            'status' => 'available',
        ])->assertRedirect();
        $this->assertDatabaseHas('resources', ['id' => $resource->id, 'name' => 'Volantes campaña']);

        $this->delete("/inventory/{$resource->id}")->assertRedirect();
        $this->delete("/meetings/{$meeting->public_id}")->assertRedirect();
        $this->delete("/people/{$person->public_id}")->assertRedirect();
        $this->assertDatabaseMissing('resources', ['id' => $resource->id]);
        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
        $this->assertSoftDeleted('persons', ['id' => $person->id]);
    }

    public function test_decision_queue_links_to_filtered_management_views(): void
    {
        [$organization, $campaign, $admin] = $this->adminContext();

        Person::create([
            'public_id' => (string) str()->ulid(),
            'campaign_id' => $campaign->id,
            'name' => 'Persona Pendiente',
            'search_name' => 'persona pendiente',
            'status' => 'pending',
        ]);
        Meeting::create([
            'public_id' => (string) str()->ulid(),
            'campaign_id' => $campaign->id,
            'requested_by' => $admin->id,
            'type' => 'reunion',
            'title' => 'Solicitud pendiente',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'requested',
        ]);
        Resource::create([
            'organization_id' => $organization->id,
            'campaign_id' => $campaign->id,
            'name' => 'Refrigerios',
            'kind' => 'consumable',
            'unit' => 'unidad',
            'quantity' => 5,
            'minimum_quantity' => 20,
        ]);

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('decisionQueue.0.href', '/meetings?status=requested')
                ->where('decisionQueue.0.count', 1)
                ->where('decisionQueue.1.href', '/people?status=pending')
                ->where('decisionQueue.1.count', 1)
                ->where('decisionQueue.2.href', '/inventory?alert=low')
                ->where('decisionQueue.2.count', 1));

        $this->actingAs($admin)->get('/people?status=pending')
            ->assertInertia(fn ($page) => $page->where('filters.status', 'pending')->has('people.data', 1));
        $this->actingAs($admin)->get('/meetings?status=requested')
            ->assertInertia(fn ($page) => $page->where('filters.status', 'requested')->has('meetings', 1));
        $this->actingAs($admin)->get('/inventory?alert=low')
            ->assertInertia(fn ($page) => $page->where('filters.alert', 'low')->has('resources', 1));
    }

    private function adminContext(): array
    {
        $organization = Organization::create(['name' => 'Organización', 'slug' => 'organizacion']);
        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Campaña',
            'slug' => 'campana',
            'candidate_name' => 'Candidato',
            'office' => 'Concejo',
            'territory' => 'Villavicencio',
        ]);
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Sin permisos',
            'slug' => 'empty',
            'permissions' => [],
        ]);
        $admin = User::factory()->create(['is_super_admin' => true]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $admin->id,
            'campaign_role_id' => $role->id,
        ]);

        return [$organization, $campaign, $admin];
    }
}
