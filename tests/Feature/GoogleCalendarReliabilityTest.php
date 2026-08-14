<?php

namespace Tests\Feature;

use App\Enums\CalendarPublicationResult;
use App\Exceptions\GoogleCalendarReconnectRequired;
use App\Exceptions\GoogleCalendarSafeFailure;
use App\Jobs\ApplyCalendarReviewRejection;
use App\Jobs\ProcessCalendarOutbox;
use App\Jobs\RenewGoogleCalendarWatch;
use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\CalendarChangeReview;
use App\Models\CalendarConnection;
use App\Models\CalendarSyncRun;
use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\ExternalCalendarEvent;
use App\Models\IntegrationMapping;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\User;
use App\Services\CalendarOutbox;
use App\Services\CalendarSyncRunRecovery;
use App\Services\GoogleCalendarClientFactory;
use App\Services\GoogleCalendarFailureClassifier;
use App\Services\GoogleCalendarPublisher;
use App\Services\GoogleCalendarSync;
use App\Services\GoogleCalendarWatch;
use App\Support\Tenancy\AuthorizedContextRunner;
use App\Support\Tenancy\CampaignContextResolver;
use App\Support\Tenancy\ExecutionContextStore;
use App\Support\Tenancy\UnauthorizedExecutionContext;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class GoogleCalendarReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_jobs_resolve_campaign_from_durable_records(): void
    {
        [$campaign, $user] = $this->context('legacy');
        $connection = $this->connection($campaign, $user);
        $event = $this->externalEvent($campaign, $connection);
        $review = CalendarChangeReview::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'external_calendar_event_id' => $event->id,
            'change_type' => 'created',
            'fingerprint' => hash('sha256', $event->external_event_id),
            'status' => 'pending',
        ]);

        $sync = Mockery::mock(GoogleCalendarSync::class);
        $sync->shouldReceive('sync')->once()->andReturn(['examined' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0]);
        $syncJob = $this->legacyJob(SyncGoogleCalendarConnection::class, ['connectionId' => $connection->id, 'forceFull' => false]);
        $syncJob->handle(
            $sync,
            app(GoogleCalendarFailureClassifier::class),
            app(CampaignContextResolver::class),
            app(AuthorizedContextRunner::class),
        );

        $watch = Mockery::mock(GoogleCalendarWatch::class);
        $watch->shouldReceive('renew')->once()->withArgs(fn (CalendarConnection $value) => $value->is($connection));
        $watchJob = $this->legacyJob(RenewGoogleCalendarWatch::class, ['connectionId' => $connection->id]);
        $watchJob->handle(
            $watch,
            app(GoogleCalendarFailureClassifier::class),
            app(CampaignContextResolver::class),
            app(AuthorizedContextRunner::class),
        );

        $reviewJob = $this->legacyJob(ApplyCalendarReviewRejection::class, ['reviewId' => $review->id]);
        $reviewJob->handle(
            Mockery::mock(GoogleCalendarClientFactory::class),
            app(GoogleCalendarFailureClassifier::class),
            Mockery::mock(GoogleCalendarPublisher::class),
            app(CampaignContextResolver::class),
            app(AuthorizedContextRunner::class),
            app(ExecutionContextStore::class),
        );

        $this->assertNull($syncJob->campaignId);
        $this->assertNull($watchJob->campaignId);
        $this->assertNull($reviewJob->campaignId);
    }

    public function test_legacy_job_with_missing_durable_reference_fails_closed_before_google(): void
    {
        $sync = Mockery::mock(GoogleCalendarSync::class);
        $sync->shouldNotReceive('sync');
        $job = $this->legacyJob(SyncGoogleCalendarConnection::class, ['connectionId' => 999999, 'forceFull' => false]);

        $this->expectException(UnauthorizedExecutionContext::class);
        $job->handle(
            $sync,
            app(GoogleCalendarFailureClassifier::class),
            app(CampaignContextResolver::class),
            app(AuthorizedContextRunner::class),
        );
    }

    public function test_campaign_outbox_failures_are_isolated_and_publication_is_explicit(): void
    {
        [$campaignA, $userA, $organization] = $this->context('outbox-a');
        [$campaignB, $userB] = $this->context('outbox-b', $organization);
        $this->connection($campaignA, $userA, 'calendar-a@example.test');
        $this->connection($campaignB, $userB, 'calendar-b@example.test');
        $meetingA = $this->meeting($campaignA, $userA);
        $meetingB = $this->meeting($campaignB, $userB);
        $eventA = app(CalendarOutbox::class)->meetingUpsert($meetingA);
        $eventB = app(CalendarOutbox::class)->meetingUpsert($meetingB);
        $publisher = Mockery::mock(GoogleCalendarPublisher::class);
        $publisher->shouldReceive('upsert')->once()->withArgs(fn (Meeting $meeting) => $meeting->is($meetingA))
            ->andThrow(new GoogleCalendarReconnectRequired('raw provider secret access_token=secret'));
        $publisher->shouldReceive('upsert')->once()->withArgs(fn (Meeting $meeting) => $meeting->is($meetingB))
            ->andReturn(CalendarPublicationResult::Confirmed);

        $this->processOutbox($campaignA, $publisher);
        $this->processOutbox($campaignB, $publisher);

        $this->assertNull($eventA->fresh()->published_at);
        $this->assertSame('Google revocó la autorización. Vuelve a vincular la cuenta.', $eventA->fresh()->last_error);
        $this->assertNotNull($eventB->fresh()->published_at);
        $this->assertStringNotContainsString('access_token', (string) $eventA->fresh()->last_error);
    }

    public function test_outbox_stays_pending_when_calendar_connection_is_not_ready(): void
    {
        [$campaign, $user] = $this->context('deferred');
        $meeting = $this->meeting($campaign, $user);
        $event = app(CalendarOutbox::class)->meetingUpsert($meeting);

        $this->processOutbox($campaign, app(GoogleCalendarPublisher::class));

        $this->assertNull($event->fresh()->published_at);
        $this->assertSame('La publicación espera una conexión activa de Google Calendar.', $event->fresh()->last_error);
    }

    public function test_retry_adopts_deterministic_remote_event_without_inserting_a_duplicate(): void
    {
        [$campaign, $user] = $this->context('idempotent');
        $connection = $this->connection($campaign, $user);
        $meeting = $this->meeting($campaign, $user);
        $eventId = 't'.substr(hash('sha256', implode('|', [
            'territorio',
            $campaign->id,
            $meeting->public_id,
            $connection->calendar_id,
        ])), 0, 63);
        $saved = new Event([
            'id' => $eventId,
            'etag' => '"etag-safe"',
            'summary' => $meeting->title,
            'status' => 'confirmed',
            'htmlLink' => 'https://calendar.google.com/calendar/event?eid=safe',
            'start' => ['dateTime' => $meeting->starts_at->toRfc3339String()],
            'end' => ['dateTime' => $meeting->ends_at->toRfc3339String()],
            'extendedProperties' => ['private' => [
                'territorio_campaign_id' => (string) $campaign->id,
                'territorio_meeting_id' => (string) $meeting->id,
                'territorio_meeting_public_id' => $meeting->public_id,
            ]],
        ]);
        $events = Mockery::mock();
        $events->shouldReceive('insert')->once()->withArgs(function ($calendarId, Event $event) use ($connection, $eventId) {
            return $calendarId === $connection->calendar_id && $event->getId() === $eventId;
        })->andThrow(new GoogleServiceException('remote body', 409));
        $events->shouldReceive('get')->once()->with($connection->calendar_id, $eventId)->andReturn($saved);
        $service = Mockery::mock(Calendar::class);
        $service->events = $events;
        $factory = Mockery::mock(GoogleCalendarClientFactory::class);
        $factory->shouldReceive('service')->once()->withArgs(fn (CalendarConnection $value) => $value->is($connection))->andReturn($service);
        $publisher = new GoogleCalendarPublisher($factory, app(ExecutionContextStore::class));
        $context = app(CampaignContextResolver::class)->fromCalendarConnection($campaign->id, $connection->id);

        $result = app(AuthorizedContextRunner::class)->run($context, fn () => $publisher->upsert($meeting));

        $this->assertSame(CalendarPublicationResult::Confirmed, $result);
        $this->assertDatabaseHas('integration_mappings', [
            'campaign_id' => $campaign->id,
            'local_id' => (string) $meeting->id,
            'external_id' => $eventId,
        ]);
        $this->assertSame($eventId, $meeting->fresh()->google_event_id);
    }

    public function test_sync_failures_are_sanitized_and_terminal_state_cannot_be_degraded(): void
    {
        [$campaign, $user] = $this->context('safe-failure');
        $connection = $this->connection($campaign, $user);
        $run = $this->syncRun($campaign, $connection, 'queued');
        $sync = Mockery::mock(GoogleCalendarSync::class);
        $sync->shouldReceive('sync')->once()->andThrow(new GoogleServiceException(
            'provider access_token=secret client_secret=secret',
            500,
        ));
        $job = new SyncGoogleCalendarConnection($campaign->id, $connection->id, false, $run->id);

        try {
            $job->handle(
                $sync,
                app(GoogleCalendarFailureClassifier::class),
                app(CampaignContextResolver::class),
                app(AuthorizedContextRunner::class),
            );
            $this->fail('A retryable failure must leave the queue through a safe exception.');
        } catch (GoogleCalendarSafeFailure $exception) {
            $this->assertSame('google_unavailable', $exception->failureCode);
            $this->assertNull($exception->getPrevious());
        }

        $this->assertStringNotContainsString('secret', (string) $run->fresh()->safe_message);
        $this->assertStringNotContainsString('secret', (string) $connection->fresh()->last_error);

        $run->finish('succeeded', counts: ['examined' => 0], message: 'ok');
        $this->assertFalse($run->finish('failed', errorCode: 'late_job', message: 'late'));
        $this->assertSame('succeeded', $run->fresh()->status);
    }

    public function test_definitive_auth_failure_requires_reconnection_without_retrying(): void
    {
        [$campaign, $user] = $this->context('auth-failure');
        $connection = $this->connection($campaign, $user);
        $run = $this->syncRun($campaign, $connection, 'queued');
        $sync = Mockery::mock(GoogleCalendarSync::class);
        $sync->shouldReceive('sync')->once()->andThrow(new GoogleServiceException(
            'access_token=secret',
            403,
            null,
            [['reason' => 'authError']],
        ));

        (new SyncGoogleCalendarConnection($campaign->id, $connection->id, false, $run->id))->handle(
            $sync,
            app(GoogleCalendarFailureClassifier::class),
            app(CampaignContextResolver::class),
            app(AuthorizedContextRunner::class),
        );

        $this->assertSame('reconnect_required', $connection->fresh()->status);
        $this->assertNull($connection->fresh()->refresh_token);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('reconnect_required', $run->fresh()->error_code);
        $this->assertStringNotContainsString('secret', (string) $run->fresh()->safe_message);
    }

    public function test_stale_queued_and_running_runs_are_recovered(): void
    {
        [$campaign, $user, $organization] = $this->context('recovery');
        $connection = $this->connection($campaign, $user);
        $queued = $this->syncRun($campaign, $connection, 'queued');
        $queued->forceFill(['queued_at' => now()->subMinutes(10), 'heartbeat_at' => null])->save();
        [$runningCampaign, $runningUser] = $this->context('recovery-running', $organization);
        $runningConnection = $this->connection($runningCampaign, $runningUser, 'running@example.test');
        $running = CalendarSyncRun::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $runningCampaign->id,
            'calendar_connection_id' => $runningConnection->id,
            'trigger' => 'polling',
            'status' => 'running',
            'active_key' => $runningCampaign->id.':'.$runningConnection->id,
            'queued_at' => now()->subHour(),
            'started_at' => now()->subHour(),
            'lease_owner' => (string) Str::uuid(),
            'lease_expires_at' => now()->subMinute(),
            'heartbeat_at' => now()->subHour(),
        ]);
        Queue::fake();

        $counts = app(CalendarSyncRunRecovery::class)->recover();

        $this->assertSame(['queued' => 1, 'running' => 1], $counts);
        $this->assertSame('queued', $running->fresh()->status);
        $this->assertSame('lease_expired', $running->fresh()->error_code);
        Queue::assertPushed(SyncGoogleCalendarConnection::class, 2);
    }

    public function test_calendar_view_never_uses_mapping_or_link_from_previous_calendar(): void
    {
        [$campaign, $user] = $this->context('active-calendar');
        $connection = $this->connection($campaign, $user, 'new-calendar@example.test');
        $meeting = $this->meeting($campaign, $user);
        $meeting->update(['google_event_id' => 'old-event', 'google_etag' => '"old"']);
        IntegrationMapping::create([
            'campaign_id' => $campaign->id,
            'system' => 'google_calendar',
            'entity_type' => 'meeting',
            'local_id' => (string) $meeting->id,
            'external_id' => 'old-event',
            'metadata' => ['calendar_connection_id' => $connection->id, 'calendar_id' => 'old-calendar@example.test'],
        ]);
        ExternalCalendarEvent::create([
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'meeting_id' => $meeting->id,
            'external_event_id' => 'old-event',
            'instance_key' => '',
            'etag' => '"old"',
            'title' => $meeting->title,
            'starts_at' => $meeting->starts_at,
            'ends_at' => $meeting->ends_at,
            'origin' => 'platform',
            'review_status' => 'approved',
            'html_link' => 'https://calendar.google.com/old-secret-link',
        ]);

        $this->actingAs($user)->get('/meetings?month='.$meeting->starts_at->format('Y-m'))
            ->assertInertia(fn ($page) => $page
                ->where('meetings.0.googleSync', 'queued')
                ->where('meetings.0.googleHtmlLink', null));
    }

    private function processOutbox(Campaign $campaign, GoogleCalendarPublisher $publisher): void
    {
        (new ProcessCalendarOutbox($campaign->id))->handle(
            $publisher,
            app(GoogleCalendarFailureClassifier::class),
            app(CampaignContextResolver::class),
            app(AuthorizedContextRunner::class),
            app(ExecutionContextStore::class),
        );
    }

    /** @param array<string, mixed> $properties */
    private function legacyJob(string $class, array $properties): object
    {
        $serialized = 'O:'.strlen($class).':"'.$class.'":'.count($properties).':{';
        foreach ($properties as $name => $value) {
            $serialized .= 's:'.strlen($name).':"'.$name.'";'.serialize($value);
        }

        return unserialize($serialized.'}');
    }

    private function context(string $suffix, ?Organization $organization = null): array
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
        $user = User::factory()->create(['is_super_admin' => true]);
        CampaignMembership::create(['campaign_id' => $campaign->id, 'user_id' => $user->id]);

        return [$campaign, $user, $organization];
    }

    private function connection(Campaign $campaign, User $user, string $calendarId = 'calendar@example.test'): CalendarConnection
    {
        return CalendarConnection::create([
            'campaign_id' => $campaign->id,
            'calendar_id' => $calendarId,
            'calendar_name' => 'Agenda candidato',
            'access_token' => ['access_token' => 'synthetic', 'expires_in' => 3600, 'created' => time()],
            'refresh_token' => 'synthetic-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'active',
            'connected_by' => $user->id,
        ]);
    }

    private function meeting(Campaign $campaign, User $user): Meeting
    {
        return Meeting::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'requested_by' => $user->id,
            'type' => 'reunion',
            'title' => 'Reunión sintética',
            'location' => 'Laboratorio',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'expected_attendees' => 10,
            'status' => 'approved',
        ]);
    }

    private function externalEvent(Campaign $campaign, CalendarConnection $connection): ExternalCalendarEvent
    {
        return ExternalCalendarEvent::create([
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'external_event_id' => (string) Str::uuid(),
            'instance_key' => '',
            'title' => 'Evento sintético',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'origin' => 'google',
        ]);
    }

    private function syncRun(Campaign $campaign, CalendarConnection $connection, string $status): CalendarSyncRun
    {
        return CalendarSyncRun::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'calendar_connection_id' => $connection->id,
            'trigger' => 'manual',
            'status' => $status,
            'active_key' => in_array($status, ['queued', 'running'], true) ? $campaign->id.':'.$connection->id : null,
            'queued_at' => now(),
            'finished_at' => in_array($status, ['succeeded', 'failed'], true) ? now() : null,
        ]);
    }
}
