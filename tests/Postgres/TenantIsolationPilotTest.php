<?php

namespace Tests\Postgres;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationPilotTest extends TestCase
{
    private const CONSTRAINTS = [
        'campaign_memberships_campaign_role_campaign_fk',
        'attendances_meeting_campaign_fk',
        'attendances_person_campaign_fk',
        'external_events_connection_campaign_fk',
        'external_events_meeting_campaign_fk',
        'calendar_sync_runs_connection_campaign_fk',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || ! filter_var(env('TENANT_ISOLATION_PG_TESTS'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('La suite requiere el laboratorio PostgreSQL explícito.');
        }

        $database = (string) DB::scalar('SELECT current_database()');
        if (! str_ends_with($database, '_tenant_isolation_test')) {
            $this->fail('La suite solo puede modificar una base desechable terminada en _tenant_isolation_test.');
        }

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_five_relationships_reject_cross_campaign_rows_and_preserve_delete_semantics(): void
    {
        $fixture = $this->fixture();

        $this->assertForeignKeyViolation(fn () => DB::table('campaign_memberships')->insert([
            'campaign_id' => $fixture['campaign_a'],
            'user_id' => $fixture['user_cross_role'],
            'campaign_role_id' => $fixture['role_b'],
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertForeignKeyViolation(fn () => $this->insertAttendance(
            $fixture['campaign_a'],
            $fixture['meeting_b'],
            $fixture['person_a'],
        ));
        $this->assertForeignKeyViolation(fn () => $this->insertAttendance(
            $fixture['campaign_a'],
            $fixture['meeting_a'],
            $fixture['person_b'],
        ));
        $this->assertForeignKeyViolation(fn () => $this->insertExternalEvent(
            $fixture['campaign_a'],
            $fixture['connection_b'],
            null,
        ));
        $this->assertForeignKeyViolation(fn () => $this->insertExternalEvent(
            $fixture['campaign_a'],
            $fixture['connection_a'],
            $fixture['meeting_b'],
        ));

        $membershipId = DB::table('campaign_memberships')->insertGetId([
            'campaign_id' => $fixture['campaign_a'],
            'user_id' => $fixture['user_valid_role'],
            'campaign_role_id' => $fixture['role_a'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('campaign_roles')->where('id', $fixture['role_a'])->delete();
        $membership = DB::table('campaign_memberships')->where('id', $membershipId)->first();
        $this->assertSame($fixture['campaign_a'], (int) $membership->campaign_id);
        $this->assertNull($membership->campaign_role_id);

        $attendanceByMeeting = $this->insertAttendance(
            $fixture['campaign_a'],
            $fixture['meeting_a'],
            $fixture['person_a'],
        );
        DB::table('meetings')->where('id', $fixture['meeting_a'])->delete();
        $this->assertFalse(DB::table('attendances')->where('id', $attendanceByMeeting)->exists());

        $meetingForPersonDelete = $this->insertMeeting($fixture['campaign_a'], 'person-delete');
        $attendanceByPerson = $this->insertAttendance(
            $fixture['campaign_a'],
            $meetingForPersonDelete,
            $fixture['person_a'],
        );
        DB::table('persons')->where('id', $fixture['person_a'])->delete();
        $this->assertFalse(DB::table('attendances')->where('id', $attendanceByPerson)->exists());

        $meetingForEvent = $this->insertMeeting($fixture['campaign_a'], 'event-null');
        $eventToKeep = $this->insertExternalEvent(
            $fixture['campaign_a'],
            $fixture['connection_a'],
            $meetingForEvent,
        );
        DB::table('meetings')->where('id', $meetingForEvent)->delete();
        $keptEvent = DB::table('external_calendar_events')->where('id', $eventToKeep)->first();
        $this->assertSame($fixture['campaign_a'], (int) $keptEvent->campaign_id);
        $this->assertNull($keptEvent->meeting_id);

        $eventToCascade = $this->insertExternalEvent(
            $fixture['campaign_a'],
            $fixture['connection_a'],
            null,
        );
        DB::table('calendar_connections')->where('id', $fixture['connection_a'])->delete();
        $this->assertFalse(DB::table('external_calendar_events')->where('id', $eventToCascade)->exists());
    }

    public function test_not_valid_protects_new_rows_and_validation_remains_a_separate_step(): void
    {
        $fixture = $this->fixture();
        $constraint = 'campaign_memberships_campaign_role_campaign_fk';
        DB::statement("ALTER TABLE campaign_memberships DROP CONSTRAINT {$constraint}");

        $historicalId = DB::table('campaign_memberships')->insertGetId([
            'campaign_id' => $fixture['campaign_a'],
            'user_id' => $fixture['user_cross_role'],
            'campaign_role_id' => $fixture['role_b'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement(<<<'SQL'
            ALTER TABLE campaign_memberships
            ADD CONSTRAINT campaign_memberships_campaign_role_campaign_fk
            FOREIGN KEY (campaign_id, campaign_role_id)
            REFERENCES campaign_roles (campaign_id, id)
            MATCH SIMPLE ON UPDATE NO ACTION
            ON DELETE SET NULL (campaign_role_id)
            NOT VALID
            SQL);

        $this->assertFalse($this->constraintValidated($constraint));
        $this->assertForeignKeyViolation(fn () => DB::table('campaign_memberships')->insert([
            'campaign_id' => $fixture['campaign_a'],
            'user_id' => $fixture['user_new_cross_role'],
            'campaign_role_id' => $fixture['role_b'],
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertForeignKeyViolation(
            fn () => DB::statement("ALTER TABLE campaign_memberships VALIDATE CONSTRAINT {$constraint}"),
        );

        DB::table('campaign_memberships')->where('id', $historicalId)->delete();
        DB::statement("ALTER TABLE campaign_memberships VALIDATE CONSTRAINT {$constraint}");
        $this->assertTrue($this->constraintValidated($constraint));

        foreach (array_diff(self::CONSTRAINTS, [$constraint]) as $name) {
            DB::statement('ALTER TABLE '.match ($name) {
                'attendances_meeting_campaign_fk', 'attendances_person_campaign_fk' => 'attendances',
                'calendar_sync_runs_connection_campaign_fk' => 'calendar_sync_runs',
                default => 'external_calendar_events',
            }." VALIDATE CONSTRAINT {$name}");
            $this->assertTrue($this->constraintValidated($name));
        }
    }

    public function test_calendar_sync_runs_reject_cross_campaign_and_invalid_lifecycle_rows(): void
    {
        $fixture = $this->fixture();

        $this->assertForeignKeyViolation(fn () => $this->insertSyncRun(
            $fixture['campaign_a'],
            $fixture['connection_b'],
            'queued',
            $fixture['campaign_a'].':'.$fixture['connection_b'],
        ));
        $this->assertCheckViolation(fn () => $this->insertSyncRun(
            $fixture['campaign_a'],
            $fixture['connection_a'],
            'invalid-status',
            null,
        ));
        $this->assertCheckViolation(fn () => $this->insertSyncRun(
            $fixture['campaign_a'],
            $fixture['connection_a'],
            'queued',
            null,
        ));

        $runId = $this->insertSyncRun(
            $fixture['campaign_a'],
            $fixture['connection_a'],
            'queued',
            $fixture['campaign_a'].':'.$fixture['connection_a'],
        );
        DB::table('calendar_connections')->where('id', $fixture['connection_a'])->delete();
        $this->assertFalse(DB::table('calendar_sync_runs')->where('id', $runId)->exists());
    }

    private function fixture(): array
    {
        $organization = DB::table('organizations')->insertGetId([
            'name' => 'Synthetic tenant isolation laboratory',
            'slug' => 'synthetic-'.Str::lower(Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $campaignA = $this->insertCampaign($organization, 'a');
        $campaignB = $this->insertCampaign($organization, 'b');

        return [
            'campaign_a' => $campaignA,
            'campaign_b' => $campaignB,
            'role_a' => $this->insertRole($campaignA, 'a'),
            'role_b' => $this->insertRole($campaignB, 'b'),
            'person_a' => $this->insertPerson($campaignA, 'a'),
            'person_b' => $this->insertPerson($campaignB, 'b'),
            'meeting_a' => $this->insertMeeting($campaignA, 'a'),
            'meeting_b' => $this->insertMeeting($campaignB, 'b'),
            'connection_a' => $this->insertConnection($campaignA, 'a'),
            'connection_b' => $this->insertConnection($campaignB, 'b'),
            'user_valid_role' => $this->insertUser('valid-role'),
            'user_cross_role' => $this->insertUser('cross-role'),
            'user_new_cross_role' => $this->insertUser('new-cross-role'),
        ];
    }

    private function insertCampaign(int $organizationId, string $suffix): int
    {
        return DB::table('campaigns')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Synthetic campaign '.$suffix,
            'slug' => 'synthetic-'.$suffix.'-'.Str::lower(Str::random(6)),
            'candidate_name' => 'Synthetic candidate',
            'office' => 'Test office',
            'territory' => 'Synthetic laboratory',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertRole(int $campaignId, string $suffix): int
    {
        return DB::table('campaign_roles')->insertGetId([
            'campaign_id' => $campaignId,
            'name' => 'Synthetic role '.$suffix,
            'slug' => 'synthetic-'.$suffix.'-'.Str::lower(Str::random(6)),
            'permissions' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPerson(int $campaignId, string $suffix): int
    {
        return DB::table('persons')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaignId,
            'name' => 'Synthetic person '.$suffix,
            'search_name' => 'synthetic person '.$suffix,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMeeting(int $campaignId, string $suffix): int
    {
        return DB::table('meetings')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaignId,
            'type' => 'synthetic',
            'title' => 'Synthetic meeting '.$suffix,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertConnection(int $campaignId, string $suffix): int
    {
        return DB::table('calendar_connections')->insertGetId([
            'campaign_id' => $campaignId,
            'calendar_id' => 'synthetic-'.$suffix.'-'.Str::lower(Str::random(6)).'@example.test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertUser(string $suffix): int
    {
        return DB::table('users')->insertGetId([
            'name' => 'Synthetic user',
            'email' => $suffix.'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'synthetic-not-a-real-password',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAttendance(int $campaignId, int $meetingId, int $personId): int
    {
        return DB::table('attendances')->insertGetId([
            'campaign_id' => $campaignId,
            'meeting_id' => $meetingId,
            'person_id' => $personId,
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertExternalEvent(int $campaignId, int $connectionId, ?int $meetingId): int
    {
        return DB::table('external_calendar_events')->insertGetId([
            'campaign_id' => $campaignId,
            'calendar_connection_id' => $connectionId,
            'meeting_id' => $meetingId,
            'external_event_id' => (string) Str::uuid(),
            'instance_key' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSyncRun(
        int $campaignId,
        int $connectionId,
        string $status,
        ?string $activeKey,
    ): int {
        return DB::table('calendar_sync_runs')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'campaign_id' => $campaignId,
            'calendar_connection_id' => $connectionId,
            'trigger' => 'manual',
            'status' => $status,
            'active_key' => $activeKey,
            'queued_at' => now(),
            'finished_at' => in_array($status, ['succeeded', 'failed'], true) ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertForeignKeyViolation(callable $operation): void
    {
        $savepoint = 'tenant_isolation_expected_failure';
        DB::statement("SAVEPOINT {$savepoint}");

        try {
            $operation();
            $this->fail('PostgreSQL accepted a cross-campaign relationship.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0] ?? null);
        } finally {
            DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
            DB::statement("RELEASE SAVEPOINT {$savepoint}");
        }
    }

    private function assertCheckViolation(callable $operation): void
    {
        $savepoint = 'calendar_sync_run_expected_failure';
        DB::statement("SAVEPOINT {$savepoint}");

        try {
            $operation();
            $this->fail('PostgreSQL accepted an invalid calendar sync lifecycle.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0] ?? null);
        } finally {
            DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
            DB::statement("RELEASE SAVEPOINT {$savepoint}");
        }
    }

    private function constraintValidated(string $constraint): bool
    {
        return (bool) DB::scalar(
            'SELECT convalidated FROM pg_constraint WHERE conname = ?',
            [$constraint],
        );
    }
}
