<?php

namespace App\Services;

use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\CalendarConnection;
use App\Models\CalendarSyncRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CalendarSyncDispatcher
{
    public function dispatch(
        CalendarConnection $connection,
        string $trigger,
        ?int $requestedBy = null,
        bool $forceFull = false,
    ): CalendarSyncRun {
        $created = false;
        $run = DB::transaction(function () use ($connection, $trigger, $requestedBy, $forceFull, &$created) {
            $lockedConnection = CalendarConnection::query()
                ->where('campaign_id', $connection->campaign_id)
                ->whereKey($connection->id)
                ->lockForUpdate()
                ->firstOrFail();
            $activeKey = $lockedConnection->campaign_id.':'.$lockedConnection->id;
            $active = CalendarSyncRun::query()
                ->where('campaign_id', $lockedConnection->campaign_id)
                ->where('calendar_connection_id', $lockedConnection->id)
                ->where('active_key', $activeKey)
                ->first();

            if ($active) {
                return $active;
            }

            $created = true;

            return CalendarSyncRun::create([
                'public_id' => (string) Str::ulid(),
                'campaign_id' => $lockedConnection->campaign_id,
                'calendar_connection_id' => $lockedConnection->id,
                'requested_by' => $requestedBy,
                'trigger' => $trigger,
                'force_full' => $forceFull,
                'status' => 'queued',
                'active_key' => $activeKey,
                'queued_at' => now(),
                'safe_message' => 'Esperando turno para sincronizar con Google Calendar.',
            ]);
        });

        if ($created) {
            SyncGoogleCalendarConnection::dispatch(
                (int) $run->campaign_id,
                (int) $run->calendar_connection_id,
                (bool) $run->force_full,
                (int) $run->id,
            );
        }

        return $run;
    }
}
