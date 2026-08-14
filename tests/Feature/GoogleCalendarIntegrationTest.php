<?php

namespace Tests\Feature;

use App\Jobs\ApplyCalendarReviewRejection;
use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\CalendarChangeReview;
use App\Models\CalendarConnection;
use App\Models\CalendarSyncRun;
use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\CampaignRole;
use App\Models\CampaignServiceCredential;
use App\Models\ExternalCalendarEvent;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\User;
use App\Services\CalendarSyncDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleCalendarIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_busy_block_prevents_approving_an_overlapping_meeting(): void
    {
        [$campaign, $admin] = $this->context();
        $connection = $this->connection($campaign, $admin);
        $day = now()->addDay()->startOfDay();
        $this->externalEvent($campaign, $connection, $day->copy()->setTime(10, 0), $day->copy()->setTime(11, 0));
        $meeting = $this->meeting($campaign, $admin, $day->copy()->setTime(10, 30), $day->copy()->setTime(11, 30));

        $this->actingAs($admin)
            ->post("/meetings/{$meeting->public_id}/approve")
            ->assertSessionHasErrors('meeting');

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id, 'status' => 'requested']);
    }

    public function test_editing_an_approved_schedule_creates_a_reapproval_request_without_changing_the_agenda(): void
    {
        [$campaign, $admin] = $this->context();
        $startsAt = now()->addDay()->setTime(9, 0);
        $meeting = $this->meeting($campaign, $admin, $startsAt, $startsAt->copy()->addHour(), 'approved');

        $this->actingAs($admin)->put("/meetings/{$meeting->public_id}", [
            'title' => $meeting->title,
            'type' => $meeting->type,
            'location' => 'Salón comunal',
            'address' => 'Carrera 10 # 20-30',
            'latitude' => 4.142,
            'longitude' => -73.6266,
            'starts_at' => $startsAt->copy()->addHours(3)->format('Y-m-d H:i:s'),
            'ends_at' => $startsAt->copy()->addHours(4)->format('Y-m-d H:i:s'),
            'expected_attendees' => 20,
        ])->assertSessionDoesntHaveErrors();

        $this->assertTrue($meeting->fresh()->starts_at->equalTo($startsAt));
        $this->assertDatabaseHas('meeting_change_requests', [
            'campaign_id' => $campaign->id,
            'meeting_id' => $meeting->id,
            'status' => 'pending',
        ]);
    }

    public function test_calendar_reviews_are_isolated_by_campaign_and_rejection_is_queued(): void
    {
        [$campaign, $admin, $organization] = $this->context();
        [$otherCampaign, $otherAdmin] = $this->context($organization, 'otra');
        $connection = $this->connection($campaign, $admin);
        $otherConnection = $this->connection($otherCampaign, $otherAdmin, 'other-calendar@example.test');
        $event = $this->externalEvent($campaign, $connection, now()->addDay(), now()->addDay()->addHour());
        $otherEvent = $this->externalEvent($otherCampaign, $otherConnection, now()->addDay(), now()->addDay()->addHour());
        $review = $this->review($campaign, $connection, $event);
        $otherReview = $this->review($otherCampaign, $otherConnection, $otherEvent);

        $this->actingAs($admin)
            ->get('/calendar/reviews?status=pending')
            ->assertInertia(fn ($page) => $page
                ->has('reviews.data', 1)
                ->where('reviews.data.0.id', $review->public_id));

        $this->actingAs($admin)
            ->post("/calendar/reviews/{$otherReview->public_id}/approve")
            ->assertNotFound();

        Queue::fake();
        $this->actingAs($admin)
            ->post("/calendar/reviews/{$review->public_id}/reject", ['notes' => 'No pertenece a la agenda'])
            ->assertSessionDoesntHaveErrors();
        Queue::assertPushed(ApplyCalendarReviewRejection::class, fn ($job) => $job->campaignId === $campaign->id && $job->reviewId === $review->id);
        $this->assertDatabaseHas('calendar_change_reviews', ['id' => $review->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('external_calendar_events', ['id' => $event->id, 'review_status' => 'rejection_pending']);
    }

    public function test_webhook_requires_the_exact_channel_resource_and_secret(): void
    {
        [$campaign, $admin] = $this->context();
        $connection = $this->connection($campaign, $admin);
        $connection->update([
            'watch_channel_id' => 'channel-1',
            'watch_resource_id' => 'resource-1',
            'watch_token_hash' => hash('sha256', 'secret-token'),
        ]);

        $this->post('/webhooks/google-calendar/v1', [], [
            'X-Goog-Channel-ID' => 'channel-1',
            'X-Goog-Resource-ID' => 'resource-1',
            'X-Goog-Channel-Token' => 'wrong',
        ])->assertForbidden();

        Queue::fake();
        $this->post('/webhooks/google-calendar/v1', [], [
            'X-Goog-Channel-ID' => 'channel-1',
            'X-Goog-Resource-ID' => 'resource-1',
            'X-Goog-Channel-Token' => 'secret-token',
        ])->assertNoContent();
        Queue::assertPushed(SyncGoogleCalendarConnection::class, fn ($job) => $job->campaignId === $campaign->id && $job->connectionId === $connection->id);
        $this->assertDatabaseHas('calendar_sync_runs', [
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'trigger' => 'webhook',
            'status' => 'queued',
        ]);
    }

    public function test_repeated_sync_requests_share_one_active_run_and_one_job(): void
    {
        [$campaign, $admin] = $this->context();
        $connection = $this->connection($campaign, $admin);
        Queue::fake();

        $dispatcher = app(CalendarSyncDispatcher::class);
        $first = $dispatcher->dispatch($connection, 'manual', $admin->id);
        $second = $dispatcher->dispatch($connection, 'manual', $admin->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CalendarSyncRun::where('campaign_id', $campaign->id)->count());
        Queue::assertPushed(SyncGoogleCalendarConnection::class, 1);
    }

    public function test_calendar_settings_only_exposes_sync_runs_from_the_active_campaign(): void
    {
        [$campaign, $admin, $organization] = $this->context();
        [$otherCampaign, $otherAdmin] = $this->context($organization, 'otra-sync');
        $connection = $this->connection($campaign, $admin);
        $otherConnection = $this->connection($otherCampaign, $otherAdmin, 'other-sync@example.test');
        $this->syncRun($campaign, $connection, 'succeeded');
        $this->syncRun($otherCampaign, $otherConnection, 'failed');

        $this->actingAs($admin)->get('/calendar/settings')
            ->assertInertia(fn ($page) => $page
                ->has('syncRuns', 1)
                ->where('syncRuns.0.status', 'succeeded'));
    }

    public function test_not_ready_connection_finishes_a_tracked_run_without_retrying(): void
    {
        [$campaign, $admin] = $this->context();
        $connection = $this->connection($campaign, $admin);
        $connection->update([
            'status' => 'reconnect_required',
            'access_token' => null,
            'refresh_token' => null,
        ]);
        $run = $this->syncRun($campaign, $connection, 'queued', active: true);

        $job = new SyncGoogleCalendarConnection($campaign->id, $connection->id, false, $run->id);
        app()->call([$job, 'handle']);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('connection_not_ready', $run->error_code);
        $this->assertNull($run->active_key);
    }

    public function test_same_google_calendar_cannot_be_assigned_to_two_campaigns(): void
    {
        [$campaign, $admin, $organization] = $this->context();
        [$otherCampaign, $otherAdmin] = $this->context($organization, 'otra');
        $this->connection($campaign, $admin, 'candidate@example.test');

        $this->expectException(QueryException::class);
        $this->connection($otherCampaign, $otherAdmin, 'candidate@example.test');
    }

    public function test_super_admin_stores_encrypted_google_credentials_per_campaign_and_secret_is_never_rendered(): void
    {
        [$campaign, $admin] = $this->context();

        $this->actingAs($admin)->put('/calendar/settings/service', [
            'client_id' => 'client-villavicencio.apps.googleusercontent.com',
            'client_secret' => 'very-secret-value',
            'redirect_uri' => 'https://territorio.example/calendar/oauth/callback',
            'webhook_url' => 'https://territorio.example/webhooks/google-calendar/v1',
        ])->assertSessionDoesntHaveErrors();

        $configuration = CampaignServiceCredential::where('campaign_id', $campaign->id)
            ->where('provider', 'google_calendar')
            ->firstOrFail();
        $this->assertSame('very-secret-value', $configuration->credentials['client_secret']);
        $this->assertSame('https://territorio.example/calendar/oauth/callback', $configuration->settings['redirect_uri']);
        $this->assertStringNotContainsString('very-secret-value', $configuration->getRawOriginal('credentials'));

        $this->actingAs($admin)->get('/calendar/settings')
            ->assertInertia(fn ($page) => $page
                ->where('configured', true)
                ->where('serviceConfiguration.clientId', 'client-villavicencio.apps.googleusercontent.com')
                ->where('serviceConfiguration.secretConfigured', true)
                ->missing('serviceConfiguration.clientSecret'));
    }

    public function test_regular_user_cannot_change_service_credentials(): void
    {
        [$campaign] = $this->context();
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Agenda limitada',
            'slug' => 'limited-agenda',
            'permissions' => ['calendar.connections.manage', 'calendar.sync.view'],
        ]);
        $user = User::factory()->create(['is_super_admin' => false]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'campaign_role_id' => $role->id,
        ]);

        $this->actingAs($user)->put('/calendar/settings/service', [
            'client_id' => 'forbidden',
            'client_secret' => 'forbidden',
            'redirect_uri' => 'https://example.test/callback',
        ])->assertForbidden();

        $this->assertDatabaseMissing('campaign_service_credentials', ['campaign_id' => $campaign->id]);
    }

    public function test_changing_oauth_credentials_requires_reconnecting_the_existing_account(): void
    {
        [$campaign, $admin] = $this->context();
        $connection = $this->connection($campaign, $admin);
        CampaignServiceCredential::create([
            'campaign_id' => $campaign->id,
            'provider' => 'google_calendar',
            'label' => 'Google Calendar',
            'credentials' => ['client_id' => 'old-client', 'client_secret' => 'old-secret'],
            'settings' => ['redirect_uri' => 'https://example.test/callback'],
            'configured_by' => $admin->id,
        ]);

        $this->actingAs($admin)->put('/calendar/settings/service', [
            'client_id' => 'new-client',
            'client_secret' => '',
            'redirect_uri' => 'https://example.test/callback',
            'webhook_url' => '',
        ])->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('calendar_connections', [
            'id' => $connection->id,
            'status' => 'reconnect_required',
            'access_token' => null,
            'refresh_token' => null,
        ]);
        $this->assertSame('old-secret', CampaignServiceCredential::first()->credentials['client_secret']);
    }

    public function test_switching_campaign_never_returns_the_previous_candidate_google_configuration(): void
    {
        [$villavicencio, $admin, $organization] = $this->context();
        $acacias = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Acacías 2027',
            'slug' => 'acacias-isolated',
            'candidate_name' => 'Candidata Acacías',
            'office' => 'Concejo de Acacías',
            'territory' => 'Acacías, Meta',
        ]);
        CampaignServiceCredential::create([
            'campaign_id' => $villavicencio->id,
            'provider' => 'google_calendar',
            'label' => 'Google Calendar',
            'credentials' => ['client_id' => 'villavicencio-only-client', 'client_secret' => 'secret'],
            'settings' => ['redirect_uri' => 'https://example.test/calendar/oauth/callback'],
            'configured_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get('/calendar/settings')
            ->assertInertia(fn ($page) => $page
                ->where('currentCampaign.id', $villavicencio->id)
                ->where('serviceConfiguration.clientId', 'villavicencio-only-client'));

        $this->actingAs($admin)
            ->from('/calendar/settings')
            ->post('/campaign/switch', ['campaign_id' => $acacias->id])
            ->assertRedirect('/calendar/settings');

        $this->get('/calendar/settings')
            ->assertInertia(fn ($page) => $page
                ->where('currentCampaign.id', $acacias->id)
                ->where('currentCampaign.candidateName', 'Candidata Acacías')
                ->where('configured', false)
                ->where('serviceConfiguration.clientId', null)
                ->where('serviceConfiguration.secretConfigured', false));
    }

    private function context(?Organization $organization = null, string $suffix = 'principal'): array
    {
        $organization ??= Organization::create(['name' => 'Organización', 'slug' => 'org-'.$suffix]);
        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Campaña '.$suffix,
            'slug' => 'campaign-'.$suffix,
            'candidate_name' => 'Candidato',
            'office' => 'Concejo',
            'territory' => 'Meta',
        ]);
        $role = CampaignRole::create([
            'campaign_id' => $campaign->id,
            'name' => 'Agenda',
            'slug' => 'agenda',
            'permissions' => ['calendar.connections.manage', 'calendar.changes.review', 'calendar.sync.view', 'meetings.view', 'meetings.manage', 'meetings.approve'],
        ]);
        $admin = User::factory()->create(['is_super_admin' => true]);
        CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $admin->id,
            'campaign_role_id' => $role->id,
        ]);

        return [$campaign, $admin, $organization];
    }

    private function connection(Campaign $campaign, User $admin, string $calendarId = 'calendar@example.test'): CalendarConnection
    {
        return CalendarConnection::create([
            'campaign_id' => $campaign->id,
            'account_email' => $admin->email,
            'calendar_id' => $calendarId,
            'calendar_name' => 'Agenda candidato',
            'access_token' => ['access_token' => 'encrypted-test-token', 'expires_in' => 3600, 'created' => time()],
            'refresh_token' => 'encrypted-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
            'connected_by' => $admin->id,
        ]);
    }

    private function externalEvent(Campaign $campaign, CalendarConnection $connection, $startsAt, $endsAt): ExternalCalendarEvent
    {
        return ExternalCalendarEvent::create([
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'external_event_id' => (string) Str::uuid(),
            'instance_key' => '',
            'etag' => '"etag"',
            'title' => 'Compromiso de Google',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_busy' => true,
            'review_status' => 'pending',
            'origin' => 'google',
        ]);
    }

    private function meeting(Campaign $campaign, User $user, $startsAt, $endsAt, string $status = 'requested'): Meeting
    {
        return Meeting::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'requested_by' => $user->id,
            'type' => 'reunion',
            'title' => 'Reunión territorial',
            'location' => 'Salón comunal',
            'address' => 'Carrera 10 # 20-30',
            'latitude' => 4.142,
            'longitude' => -73.6266,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'expected_attendees' => 20,
            'status' => $status,
        ]);
    }

    private function review(Campaign $campaign, CalendarConnection $connection, ExternalCalendarEvent $event): CalendarChangeReview
    {
        return CalendarChangeReview::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'external_calendar_event_id' => $event->id,
            'change_type' => 'created',
            'fingerprint' => hash('sha256', $event->external_event_id),
            'after_payload' => ['title' => $event->title],
            'status' => 'pending',
        ]);
    }

    private function syncRun(Campaign $campaign, CalendarConnection $connection, string $status, bool $active = false): CalendarSyncRun
    {
        return CalendarSyncRun::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'trigger' => 'manual',
            'status' => $status,
            'active_key' => $active ? $campaign->id.':'.$connection->id : null,
            'queued_at' => now(),
            'finished_at' => $active ? null : now(),
        ]);
    }
}
