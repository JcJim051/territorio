<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\CalendarConnection;
use App\Models\ExternalCalendarEvent;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingCalendarConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_keeps_confirmed_context_and_marks_blocking_conflicts(): void
    {
        [$campaign, $admin] = $this->context();
        $day = now()->addDay()->startOfDay();
        $confirmed = $this->meeting($campaign, $admin, 'Agenda confirmada', $day->copy()->setTime(10, 0), $day->copy()->setTime(11, 0), 'approved');
        $opportunity = $this->meeting($campaign, $admin, 'Nueva oportunidad', $day->copy()->setTime(10, 30), $day->copy()->setTime(11, 30), 'requested');

        $this->actingAs($admin)
            ->get('/meetings?status=requested&month='.$day->format('Y-m'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('meetings', 2)
                ->where('filters.status', 'requested')
                ->where('summary.withConflicts', 1)
                ->where('meetings.1.id', $opportunity->public_id)
                ->where('meetings.1.hasBlockingConflict', true)
                ->where('meetings.1.conflicts.0.id', $confirmed->public_id)
                ->where('meetings.1.conflicts.0.blocking', true));
    }

    public function test_server_rechecks_conflicts_when_approving_but_allows_adjacent_times(): void
    {
        [$campaign, $admin] = $this->context();
        $day = now()->addDay()->startOfDay();
        $this->meeting($campaign, $admin, 'Agenda confirmada', $day->copy()->setTime(10, 0), $day->copy()->setTime(11, 0), 'approved');
        $overlapping = $this->meeting($campaign, $admin, 'Cruce', $day->copy()->setTime(10, 45), $day->copy()->setTime(11, 30), 'requested');
        $adjacent = $this->meeting($campaign, $admin, 'Horario contiguo', $day->copy()->setTime(11, 0), $day->copy()->setTime(12, 0), 'requested');

        $this->actingAs($admin)
            ->post("/meetings/{$overlapping->public_id}/approve")
            ->assertSessionHasErrors('meeting');
        $this->assertDatabaseHas('meetings', ['id' => $overlapping->id, 'status' => 'requested']);

        $this->actingAs($admin)
            ->post("/meetings/{$adjacent->public_id}/approve")
            ->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('meetings', ['id' => $adjacent->id, 'status' => 'approved']);
    }

    public function test_calendar_calculates_travel_window_and_warns_when_it_is_insufficient(): void
    {
        [$campaign, $admin] = $this->context();
        $day = now()->addDay()->startOfDay();
        $this->meeting($campaign, $admin, 'Actividad anterior', $day->copy()->setTime(8, 0), $day->copy()->setTime(9, 0), 'approved', [
            'location' => 'Punto A',
            'address' => 'Centro de Villavicencio',
            'latitude' => 4.1420,
            'longitude' => -73.6266,
        ]);
        $opportunity = $this->meeting($campaign, $admin, 'Oportunidad distante', $day->copy()->setTime(9, 10), $day->copy()->setTime(10, 0), 'requested', [
            'location' => 'Punto B',
            'address' => 'Sector Apiay',
            'latitude' => 4.0760,
            'longitude' => -73.5620,
        ]);

        $this->actingAs($admin)
            ->get('/meetings?month='.$day->format('Y-m'))
            ->assertInertia(fn ($page) => $page
                ->where('meetings.1.id', $opportunity->public_id)
                ->where('meetings.1.address', 'Sector Apiay')
                ->where('meetings.1.mobility.before.gapMinutes', 10)
                ->where('meetings.1.mobility.before.risk', true)
                ->where('meetings.1.mobility.before.assessment', 'insufficient'));
    }

    public function test_new_meeting_requires_an_internal_map_point(): void
    {
        [$campaign, $admin] = $this->context();

        $this->actingAs($admin)->post('/meetings', [
            'title' => 'Reunión sin punto',
            'type' => 'reunion',
            'location' => 'Salón comunal',
            'address' => 'Dirección conocida',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i:s'),
            'expected_attendees' => 20,
        ])->assertSessionHasErrors(['latitude', 'longitude']);

        $this->assertDatabaseMissing('meetings', [
            'campaign_id' => $campaign->id,
            'title' => 'Reunión sin punto',
        ]);
    }

    public function test_calendar_preserves_the_selected_day_and_explains_google_state(): void
    {
        [$campaign, $admin] = $this->context();
        $day = now()->addDays(2)->startOfDay();
        $requested = $this->meeting($campaign, $admin, 'Pendiente', $day->copy()->setTime(8, 0), $day->copy()->setTime(9, 0), 'requested');
        $approved = $this->meeting($campaign, $admin, 'Confirmada', $day->copy()->setTime(10, 0), $day->copy()->setTime(11, 0), 'approved');

        $this->actingAs($admin)
            ->get('/meetings?month='.$day->format('Y-m').'&date='.$day->format('Y-m-d'))
            ->assertInertia(fn ($page) => $page
                ->where('filters.date', $day->format('Y-m-d'))
                ->where('meetings.0.id', $requested->public_id)
                ->where('meetings.0.googleSync', 'after_approval')
                ->where('meetings.1.id', $approved->public_id)
                ->where('meetings.1.googleSync', 'not_connected')
                ->where('calendarIntegration.connected', false));

        CalendarConnection::create([
            'campaign_id' => $campaign->id,
            'calendar_id' => 'candidate-calendar',
            'calendar_name' => 'Agenda candidato',
            'account_email' => 'agenda@example.test',
            'refresh_token' => 'encrypted-by-model',
            'status' => 'active',
        ]);
        $approved->update(['google_event_id' => 'google-event-1']);

        $this->actingAs($admin)
            ->get('/meetings?month='.$day->format('Y-m').'&date='.$day->format('Y-m-d'))
            ->assertInertia(fn ($page) => $page
                ->where('meetings.1.googleSync', 'synced')
                ->where('calendarIntegration.connected', true)
                ->where('calendarIntegration.calendarName', 'Agenda candidato'));
    }

    public function test_dense_day_returns_every_activity_in_chronological_order(): void
    {
        [$campaign, $admin] = $this->context();
        $day = now()->addDays(3)->startOfDay();
        foreach (range(0, 29) as $index) {
            $start = $day->copy()->addMinutes($index * 20);
            $this->meeting($campaign, $admin, 'Actividad '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), $start, $start->copy()->addMinutes(15), 'approved');
        }

        $this->actingAs($admin)
            ->get('/meetings?month='.$day->format('Y-m').'&date='.$day->format('Y-m-d'))
            ->assertInertia(fn ($page) => $page
                ->has('meetings', 30)
                ->where('meetings.0.title', 'Actividad 00')
                ->where('meetings.29.title', 'Actividad 29'));
    }

    public function test_calendar_does_not_duplicate_a_platform_meeting_as_an_external_event(): void
    {
        [$campaign, $admin] = $this->context();
        $day = now()->addDays(4)->startOfDay();
        $meeting = $this->meeting($campaign, $admin, 'Reunión de plataforma', $day->copy()->setTime(9, 0), $day->copy()->setTime(10, 0), 'approved');
        $connection = CalendarConnection::create([
            'campaign_id' => $campaign->id,
            'calendar_id' => 'calendar-1',
            'calendar_name' => 'Agenda',
            'refresh_token' => 'token',
            'status' => 'active',
        ]);
        ExternalCalendarEvent::create([
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'meeting_id' => $meeting->id,
            'external_event_id' => 'platform-copy',
            'title' => 'Reunión de plataforma',
            'starts_at' => $meeting->starts_at,
            'ends_at' => $meeting->ends_at,
            'origin' => 'platform',
            'review_status' => 'approved',
        ]);
        ExternalCalendarEvent::create([
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'external_event_id' => 'external-event',
            'title' => 'Bloqueo externo',
            'starts_at' => $day->copy()->setTime(11, 0),
            'ends_at' => $day->copy()->setTime(12, 0),
            'origin' => 'google',
            'review_status' => 'approved',
        ]);

        $this->actingAs($admin)
            ->get('/meetings?month='.$day->format('Y-m'))
            ->assertInertia(fn ($page) => $page
                ->has('meetings', 1)
                ->has('externalEvents', 1)
                ->where('externalEvents.0.title', 'Bloqueo externo'));
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

        return [$campaign, $admin];
    }

    private function meeting(Campaign $campaign, User $user, string $title, $startsAt, $endsAt, string $status, array $location = []): Meeting
    {
        return Meeting::create([
            'public_id' => (string) str()->ulid(),
            'campaign_id' => $campaign->id,
            'requested_by' => $user->id,
            'type' => 'reunion',
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'expected_attendees' => 20,
            'status' => $status,
            ...$location,
        ]);
    }
}
