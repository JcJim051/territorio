<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array{table: string, columns: string, unique: bool}> */
    private array $indexes = [
        'campaign_roles_campaign_id_id_unique' => ['table' => 'campaign_roles', 'columns' => 'campaign_id, id', 'unique' => true],
        'meetings_campaign_id_id_unique' => ['table' => 'meetings', 'columns' => 'campaign_id, id', 'unique' => true],
        'persons_campaign_id_id_unique' => ['table' => 'persons', 'columns' => 'campaign_id, id', 'unique' => true],
        'calendar_connections_campaign_id_id_unique' => ['table' => 'calendar_connections', 'columns' => 'campaign_id, id', 'unique' => true],
        'campaign_memberships_campaign_role_campaign_idx' => ['table' => 'campaign_memberships', 'columns' => 'campaign_id, campaign_role_id', 'unique' => false],
        'attendances_meeting_campaign_idx' => ['table' => 'attendances', 'columns' => 'campaign_id, meeting_id', 'unique' => false],
        'attendances_person_campaign_idx' => ['table' => 'attendances', 'columns' => 'campaign_id, person_id', 'unique' => false],
        'external_events_connection_campaign_idx' => ['table' => 'external_calendar_events', 'columns' => 'campaign_id, calendar_connection_id', 'unique' => false],
        'external_events_meeting_campaign_idx' => ['table' => 'external_calendar_events', 'columns' => 'campaign_id, meeting_id', 'unique' => false],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $name => $definition) {
            $unique = $definition['unique'] ? 'UNIQUE ' : '';
            $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
            DB::statement(sprintf(
                'CREATE %sINDEX %sIF NOT EXISTS %s ON %s (%s)',
                $unique,
                $concurrently,
                $name,
                $definition['table'],
                $definition['columns'],
            ));

            if (DB::getDriverName() === 'pgsql') {
                $valid = DB::scalar(
                    'SELECT indexrelid::regclass IS NOT NULL AND indisvalid FROM pg_index WHERE indexrelid = ?::regclass',
                    [$name],
                );
                if (! $valid) {
                    throw new \RuntimeException("El índice {$name} no quedó válido.");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys($this->indexes)) as $name) {
            $concurrently = DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '';
            DB::statement("DROP INDEX {$concurrently}IF EXISTS {$name}");
        }
    }
};
