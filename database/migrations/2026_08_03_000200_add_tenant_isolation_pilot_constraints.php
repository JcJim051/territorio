<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $constraints = [
        'campaign_memberships_campaign_role_campaign_fk' => <<<'SQL'
            ALTER TABLE campaign_memberships
            ADD CONSTRAINT campaign_memberships_campaign_role_campaign_fk
            FOREIGN KEY (campaign_id, campaign_role_id)
            REFERENCES campaign_roles (campaign_id, id)
            MATCH SIMPLE ON UPDATE NO ACTION
            ON DELETE SET NULL (campaign_role_id)
            NOT VALID
            SQL,
        'attendances_meeting_campaign_fk' => <<<'SQL'
            ALTER TABLE attendances
            ADD CONSTRAINT attendances_meeting_campaign_fk
            FOREIGN KEY (campaign_id, meeting_id)
            REFERENCES meetings (campaign_id, id)
            MATCH SIMPLE ON UPDATE NO ACTION
            ON DELETE CASCADE
            NOT VALID
            SQL,
        'attendances_person_campaign_fk' => <<<'SQL'
            ALTER TABLE attendances
            ADD CONSTRAINT attendances_person_campaign_fk
            FOREIGN KEY (campaign_id, person_id)
            REFERENCES persons (campaign_id, id)
            MATCH SIMPLE ON UPDATE NO ACTION
            ON DELETE CASCADE
            NOT VALID
            SQL,
        'external_events_connection_campaign_fk' => <<<'SQL'
            ALTER TABLE external_calendar_events
            ADD CONSTRAINT external_events_connection_campaign_fk
            FOREIGN KEY (campaign_id, calendar_connection_id)
            REFERENCES calendar_connections (campaign_id, id)
            MATCH SIMPLE ON UPDATE NO ACTION
            ON DELETE CASCADE
            NOT VALID
            SQL,
        'external_events_meeting_campaign_fk' => <<<'SQL'
            ALTER TABLE external_calendar_events
            ADD CONSTRAINT external_events_meeting_campaign_fk
            FOREIGN KEY (campaign_id, meeting_id)
            REFERENCES meetings (campaign_id, id)
            MATCH SIMPLE ON UPDATE NO ACTION
            ON DELETE SET NULL (meeting_id)
            NOT VALID
            SQL,
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->constraints as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(array_keys($this->constraints)) as $name) {
            $table = match ($name) {
                'campaign_memberships_campaign_role_campaign_fk' => 'campaign_memberships',
                'attendances_meeting_campaign_fk', 'attendances_person_campaign_fk' => 'attendances',
                default => 'external_calendar_events',
            };
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
