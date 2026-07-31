<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeetingResourceAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_consumes_supplies_and_reserves_returnable_resources_once(): void
    {
        [$campaign, $admin, $organization] = $this->context();
        $snacks = $this->resource($organization->id, $campaign->id, 'Refrigerios', 'consumable', 100);
        $chairs = $this->resource($organization->id, $campaign->id, 'Sillas', 'asset', 50);
        $day = now()->addDay()->startOfDay();

        $this->actingAs($admin)->post('/meetings', $this->meetingData($day, [
            ['resource_id' => $snacks->id, 'quantity' => 30, 'notes' => 'Uno por asistente'],
            ['resource_id' => $chairs->id, 'quantity' => 25, 'notes' => null],
        ]))->assertSessionDoesntHaveErrors();

        $meeting = Meeting::where('campaign_id', $campaign->id)->firstOrFail();
        $this->post("/meetings/{$meeting->public_id}/approve")->assertSessionDoesntHaveErrors();
        $this->post("/meetings/{$meeting->public_id}/approve")->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('resources', ['id' => $snacks->id, 'quantity' => 70]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'meeting_id' => $meeting->id,
            'resource_id' => $snacks->id,
            'type' => 'meeting_consumption',
            'quantity' => 30,
        ]);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseHas('reservations', [
            'meeting_id' => $meeting->id,
            'resource_id' => $chairs->id,
            'quantity' => 25,
            'status' => 'confirmed',
        ]);
    }

    public function test_overlapping_meeting_is_blocked_when_returnable_capacity_is_occupied(): void
    {
        [$campaign, $admin, $organization] = $this->context();
        $projectors = Resource::create([
            'organization_id' => $organization->id,
            'campaign_id' => null,
            'name' => 'Videobeam',
            'kind' => 'equipment',
            'unit' => 'unidad',
            'quantity' => 2,
            'minimum_quantity' => 0,
            'status' => 'available',
            'is_shared' => true,
        ]);
        $day = now()->addDays(2)->startOfDay();
        $otherCampaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Otra campaña',
            'slug' => 'otra-campana',
            'candidate_name' => 'Otra candidatura',
            'office' => 'Asamblea',
            'territory' => 'Meta',
        ]);
        $otherMeeting = Meeting::create([
            'public_id' => (string) str()->ulid(),
            'campaign_id' => $otherCampaign->id,
            'type' => 'reunion',
            'title' => 'Actividad de otra campaña',
            'starts_at' => $day->copy()->setTime(10, 0),
            'ends_at' => $day->copy()->setTime(12, 0),
            'status' => 'approved',
        ]);
        DB::table('reservations')->insert([
            'campaign_id' => $otherCampaign->id,
            'meeting_id' => $otherMeeting->id,
            'resource_id' => $projectors->id,
            'quantity' => 2,
            'starts_at' => $otherMeeting->starts_at,
            'ends_at' => $otherMeeting->ends_at,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post('/meetings', $this->meetingData($day, [
            ['resource_id' => $projectors->id, 'quantity' => 1],
        ], 'Segunda actividad'))->assertRedirect()->assertSessionDoesntHaveErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('meetings', ['title' => 'Segunda actividad']);
        $second = Meeting::where('title', 'Segunda actividad')->firstOrFail();

        $this->post("/meetings/{$second->public_id}/approve")
            ->assertSessionHasErrors('resources');
        $this->assertDatabaseHas('meetings', ['id' => $second->id, 'status' => 'requested']);
        $this->assertDatabaseHas('shortage_tasks', [
            'meeting_id' => $second->id,
            'resource_id' => $projectors->id,
            'required_quantity' => 1,
            'available_quantity' => 0,
            'status' => 'open',
        ]);

        $this->get('/meetings?month='.$day->format('Y-m'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.resourceAlerts', 1)
                ->where('meetings.0.id', $second->public_id)
                ->where('meetings.0.hasResourceBlock', true)
                ->where('meetings.0.requirements.0.missing', 1));
    }

    public function test_deleting_an_approved_meeting_releases_reservations_and_restores_consumables(): void
    {
        [$campaign, $admin, $organization] = $this->context();
        $handouts = $this->resource($organization->id, $campaign->id, 'Tarjetones', 'consumable', 200);
        $chairs = $this->resource($organization->id, $campaign->id, 'Sillas', 'asset', 40);
        $day = now()->addDays(3)->startOfDay();

        $this->actingAs($admin)->post('/meetings', $this->meetingData($day, [
            ['resource_id' => $handouts->id, 'quantity' => 50],
            ['resource_id' => $chairs->id, 'quantity' => 20],
        ]))->assertSessionDoesntHaveErrors();
        $meeting = Meeting::firstOrFail();
        $this->post("/meetings/{$meeting->public_id}/approve")->assertSessionDoesntHaveErrors();
        $this->delete("/meetings/{$meeting->public_id}")->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('resources', ['id' => $handouts->id, 'quantity' => 200]);
        $this->assertDatabaseMissing('reservations', [
            'meeting_id' => $meeting->id,
            'resource_id' => $chairs->id,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'meeting_id' => null,
            'resource_id' => $handouts->id,
            'type' => 'meeting_consumption_reversal',
            'quantity' => 50,
        ]);
    }

    private function context(): array
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
            'name' => 'Gerencia',
            'slug' => 'manager',
            'permissions' => ['*'],
        ]);
        $admin = User::factory()->create(['is_super_admin' => true]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $admin->id,
            'campaign_role_id' => $role->id,
        ]);

        return [$campaign, $admin, $organization];
    }

    private function resource(int $organizationId, int $campaignId, string $name, string $kind, float $quantity): Resource
    {
        return Resource::create([
            'organization_id' => $organizationId,
            'campaign_id' => $campaignId,
            'name' => $name,
            'kind' => $kind,
            'unit' => 'unidad',
            'quantity' => $quantity,
            'minimum_quantity' => 0,
            'status' => 'available',
        ]);
    }

    private function meetingData($day, array $requirements, string $title = 'Actividad logística'): array
    {
        return [
            'title' => $title,
            'type' => 'reunion',
            'location' => 'Salón comunal',
            'address' => 'Carrera 10 # 20-30',
            'latitude' => 4.1420,
            'longitude' => -73.6266,
            'starts_at' => $day->copy()->setTime(10, 0)->format('Y-m-d H:i:s'),
            'ends_at' => $day->copy()->setTime(12, 0)->format('Y-m-d H:i:s'),
            'expected_attendees' => 30,
            'requirements' => $requirements,
        ];
    }
}
