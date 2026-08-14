<?php

namespace Tests\Feature;

use App\Jobs\ProcessCalendarOutbox;
use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\CalendarConnection;
use App\Models\Campaign;
use App\Models\CampaignMembership;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\GoogleCalendarFailureClassifier;
use App\Services\GoogleCalendarPublisher;
use App\Services\GoogleCalendarSync;
use App\Support\Tenancy\AuthorizedContextRunner;
use App\Support\Tenancy\CampaignContextResolver;
use App\Support\Tenancy\ExecutionContextStore;
use App\Support\Tenancy\UnauthorizedExecutionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AuthorizedCampaignContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_runner_accepts_only_an_authorized_context_and_always_cleans_the_store(): void
    {
        [$campaign, $membership] = $this->membershipContext('runner');
        $resolver = app(CampaignContextResolver::class);
        $runner = app(AuthorizedContextRunner::class);
        $store = app(ExecutionContextStore::class);
        $context = $resolver->fromMembership($membership);

        $result = $runner->run($context, function () use ($campaign, $store) {
            $this->assertSame($campaign->id, $store->campaignId());

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertFalse($store->hasContext());

        try {
            $runner->run($context, function () use ($store): never {
                $this->assertTrue($store->hasContext());
                throw new RuntimeException('synthetic failure');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic failure', $exception->getMessage());
        }

        $this->assertFalse($store->hasContext());
    }

    public function test_ids_source_strings_missing_and_nested_contexts_fail_closed(): void
    {
        [$campaign, $membership] = $this->membershipContext('closed');
        $resolver = app(CampaignContextResolver::class);
        $runner = app(AuthorizedContextRunner::class);
        $store = app(ExecutionContextStore::class);
        $context = $resolver->fromMembership($membership);

        $this->expectException(UnauthorizedExecutionContext::class);
        $runner->run($context, function () use ($campaign, $context, $runner, $store) {
            $store->assertCampaign($campaign->id);
            $this->assertFalse(method_exists($runner, 'runForCampaign'));

            return $runner->run($context, fn () => 'nested');
        });
    }

    public function test_inactive_membership_and_inactive_campaign_cannot_emit_context(): void
    {
        [$campaign, $membership] = $this->membershipContext('inactive-membership');
        $membership->update(['is_active' => false]);

        try {
            app(CampaignContextResolver::class)->fromMembership($membership->fresh());
            $this->fail('An inactive membership emitted a context.');
        } catch (UnauthorizedExecutionContext) {
            $this->assertTrue(true);
        }

        $membership->update(['is_active' => true]);
        $campaign->update(['status' => 'inactive']);

        $this->expectException(UnauthorizedExecutionContext::class);
        app(CampaignContextResolver::class)->fromMembership($membership->fresh());
    }

    public function test_consecutive_campaign_units_do_not_reuse_context(): void
    {
        [, $membershipA, $organization] = $this->membershipContext('campaign-a');
        [, $membershipB] = $this->membershipContext('campaign-b', $organization);
        $resolver = app(CampaignContextResolver::class);
        $runner = app(AuthorizedContextRunner::class);
        $store = app(ExecutionContextStore::class);

        foreach ([$membershipA, $membershipB] as $membership) {
            $runner->run(
                $resolver->fromMembership($membership),
                fn () => $this->assertSame((int) $membership->campaign_id, $store->campaignId()),
            );
            $this->assertFalse($store->hasContext());
        }
    }

    public function test_same_campaign_http_membership_can_compose_outbox_without_replacing_owner_context(): void
    {
        [$campaign, $membership] = $this->membershipContext('composed-outbox');
        $outbox = $this->outboxContext($campaign);
        $resolver = app(CampaignContextResolver::class);
        $runner = app(AuthorizedContextRunner::class);
        $store = app(ExecutionContextStore::class);
        $owner = $resolver->fromMembership($membership);
        $child = $resolver->fromOutboxEvent($campaign->id, $outbox->id);

        $runner->run($owner, function () use ($child, $owner, $runner, $store) {
            $result = $runner->runOutbox($child, function () use ($owner, $store) {
                $this->assertSame($owner, $store->current());

                return 'processed';
            });

            $this->assertSame('processed', $result);
            $this->assertSame($owner, $store->current());
        });

        $this->assertFalse($store->hasContext());
    }

    public function test_outbox_composition_rejects_another_campaign_and_incompatible_owner_decision(): void
    {
        [$campaignA, $membershipA, $organization] = $this->membershipContext('composition-a');
        [$campaignB] = $this->membershipContext('composition-b', $organization);
        $outboxA = $this->outboxContext($campaignA);
        $outboxB = $this->outboxContext($campaignB);
        $resolver = app(CampaignContextResolver::class);
        $runner = app(AuthorizedContextRunner::class);
        $store = app(ExecutionContextStore::class);

        try {
            $runner->run($resolver->fromMembership($membershipA), function () use ($campaignB, $outboxB, $resolver, $runner) {
                $runner->runOutbox(
                    $resolver->fromOutboxEvent($campaignB->id, $outboxB->id),
                    fn () => 'must-not-run',
                );
            });
            $this->fail('A cross-campaign outbox context was composed.');
        } catch (UnauthorizedExecutionContext) {
            $this->assertFalse($store->hasContext());
        }

        $outboxContext = $resolver->fromOutboxEvent($campaignA->id, $outboxA->id);
        try {
            $runner->run($outboxContext, function () use ($outboxContext, $runner) {
                $runner->runOutbox($outboxContext, fn () => 'must-not-run');
            });
            $this->fail('An outbox decision was accepted as an owner context.');
        } catch (UnauthorizedExecutionContext) {
            $this->assertFalse($store->hasContext());
        }
    }

    public function test_sync_job_rejects_a_connection_from_another_campaign_before_calling_google(): void
    {
        [$campaignA, , $organization] = $this->membershipContext('sync-a');
        [$campaignB] = $this->membershipContext('sync-b', $organization);
        $connection = CalendarConnection::create([
            'campaign_id' => $campaignA->id,
            'calendar_id' => 'synthetic-calendar@example.test',
            'status' => 'active',
            'refresh_token' => 'synthetic-token',
        ]);
        $sync = Mockery::mock(GoogleCalendarSync::class);
        $sync->shouldNotReceive('sync');
        $job = new SyncGoogleCalendarConnection($campaignB->id, $connection->id);

        $this->expectException(UnauthorizedExecutionContext::class);
        $job->handle(
            $sync,
            app(GoogleCalendarFailureClassifier::class),
            app(CampaignContextResolver::class),
            app(AuthorizedContextRunner::class),
        );
    }

    public function test_inconsistent_outbox_is_not_published_or_sent_to_google(): void
    {
        [$campaign] = $this->membershipContext('outbox');
        $meeting = Meeting::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'type' => 'reunion',
            'title' => 'Reunión sintética',
            'location' => 'Laboratorio',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'approved',
        ]);
        $event = OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'type' => 'calendar.meeting.upsert',
            'aggregate_type' => Meeting::class,
            'aggregate_id' => (string) $meeting->id,
            'payload' => [
                'campaign_id' => $campaign->id + 999,
                'meeting_id' => $meeting->id,
                'meeting_public_id' => $meeting->public_id,
            ],
            'occurred_at' => now(),
        ]);
        $publisher = Mockery::mock(GoogleCalendarPublisher::class);
        $publisher->shouldNotReceive('upsert');
        $publisher->shouldNotReceive('deleteByIdentifiers');

        try {
            (new ProcessCalendarOutbox($campaign->id))->handle(
                $publisher,
                app(GoogleCalendarFailureClassifier::class),
                app(CampaignContextResolver::class),
                app(AuthorizedContextRunner::class),
                app(ExecutionContextStore::class),
            );
            $this->fail('An inconsistent outbox event was accepted.');
        } catch (UnauthorizedExecutionContext) {
            $event->refresh();
            $this->assertNull($event->published_at);
            $this->assertSame(1, $event->attempts);
            $this->assertFalse(app(ExecutionContextStore::class)->hasContext());
        }
    }

    private function membershipContext(string $slug, ?Organization $organization = null): array
    {
        $organization ??= Organization::create(['name' => 'Organización sintética', 'slug' => 'org-'.$slug]);
        $campaign = Campaign::create([
            'organization_id' => $organization->id,
            'name' => 'Campaña '.$slug,
            'slug' => $slug,
            'candidate_name' => 'Candidato sintético',
            'office' => 'Concejo',
            'territory' => 'Laboratorio',
            'status' => 'active',
        ]);
        $user = User::factory()->create();
        $membership = CampaignMembership::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        return [$campaign, $membership, $organization];
    }

    private function outboxContext(Campaign $campaign): OutboxEvent
    {
        $meeting = Meeting::create([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaign->id,
            'type' => 'reunion',
            'title' => 'Outbox sintético',
            'location' => 'Laboratorio',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'approved',
        ]);

        return OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'type' => 'calendar.meeting.upsert',
            'aggregate_type' => Meeting::class,
            'aggregate_id' => (string) $meeting->id,
            'payload' => [
                'campaign_id' => $campaign->id,
                'meeting_id' => $meeting->id,
                'meeting_public_id' => $meeting->public_id,
            ],
            'occurred_at' => now(),
        ]);
    }
}
