<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_request_notifies_agenda_inside_the_current_campaign(): void
    {
        [$campaign, $requester, $agenda] = $this->context();

        $this->actingAs($requester)
            ->post('/meetings', [
                'title' => 'Reunión con comerciantes',
                'type' => 'reunion',
                'location' => 'Salón comunal',
                'address' => 'Carrera 10 # 20-30',
                'latitude' => 4.1420,
                'longitude' => -73.6266,
                'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
                'expected_attendees' => 30,
            ])
            ->assertRedirect();

        $this->assertSame(1, $agenda->notifications()->where('data->campaign_id', $campaign->id)->count());
        $this->assertSame(0, $requester->notifications()->where('data->campaign_id', $campaign->id)->count());

        $notification = $agenda->notifications()->firstOrFail();
        $this->assertSame('Nueva reunión por revisar', $notification->data['title']);

        $this->actingAs($agenda)
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('notifications.data.0.title', 'Nueva reunión por revisar')
                ->where('notifications.data.0.category', 'meeting'));

        $this->actingAs($agenda)
            ->post("/notifications/{$notification->id}/read")
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
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
        $requesterRole = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Territorial',
            'slug' => 'territorial',
            'permissions' => ['meetings.create', 'meetings.view'],
        ]);
        $agendaRole = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Agenda',
            'slug' => 'agenda',
            'permissions' => ['dashboard.view', 'meetings.view', 'meetings.approve'],
        ]);
        $requester = User::factory()->create();
        $agenda = User::factory()->create();
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $requester->id,
            'campaign_role_id' => $requesterRole->id,
        ]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $agenda->id,
            'campaign_role_id' => $agendaRole->id,
        ]);

        return [$campaign, $requester, $agenda];
    }
}
