<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $postgresConstraints = [
        'calendar_sync_runs_connection_campaign_fk' => <<<'SQL'
            ALTER TABLE calendar_sync_runs
            ADD CONSTRAINT calendar_sync_runs_connection_campaign_fk
            FOREIGN KEY (campaign_id, calendar_connection_id)
            REFERENCES calendar_connections (campaign_id, id)
            MATCH SIMPLE ON UPDATE NO ACTION ON DELETE CASCADE
            NOT VALID
            SQL,
        'calendar_sync_runs_status_check' => <<<'SQL'
            ALTER TABLE calendar_sync_runs
            ADD CONSTRAINT calendar_sync_runs_status_check
            CHECK (status IN ('queued', 'running', 'succeeded', 'failed'))
            NOT VALID
            SQL,
        'calendar_sync_runs_trigger_check' => <<<'SQL'
            ALTER TABLE calendar_sync_runs
            ADD CONSTRAINT calendar_sync_runs_trigger_check
            CHECK (trigger IN ('manual', 'calendar_selected', 'webhook', 'polling', 'reconciliation'))
            NOT VALID
            SQL,
        'calendar_sync_runs_lifecycle_check' => <<<'SQL'
            ALTER TABLE calendar_sync_runs
            ADD CONSTRAINT calendar_sync_runs_lifecycle_check
            CHECK (
                (
                    status IN ('queued', 'running')
                    AND active_key = campaign_id::text || ':' || calendar_connection_id::text
                    AND finished_at IS NULL
                )
                OR
                (
                    status IN ('succeeded', 'failed')
                    AND active_key IS NULL
                    AND finished_at IS NOT NULL
                )
            )
            NOT VALID
            SQL,
    ];

    public function up(): void
    {
        Schema::table('calendar_sync_runs', function (Blueprint $table) {
            $table->uuid('lease_owner')->nullable()->after('active_key');
            $table->timestamp('lease_expires_at')->nullable()->after('lease_owner');
            $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at');
            $table->index(['status', 'lease_expires_at'], 'calendar_sync_runs_recovery_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresConstraints as $sql) {
                DB::statement($sql);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_reverse(array_keys($this->postgresConstraints)) as $name) {
                DB::statement("ALTER TABLE calendar_sync_runs DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::table('calendar_sync_runs', function (Blueprint $table) {
            $table->dropIndex('calendar_sync_runs_recovery_idx');
            $table->dropColumn(['lease_owner', 'lease_expires_at', 'heartbeat_at']);
        });
    }
};
