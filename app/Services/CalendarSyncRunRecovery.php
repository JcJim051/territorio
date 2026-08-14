<?php

namespace App\Services;

use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\CalendarSyncRun;
use Illuminate\Support\Facades\DB;

class CalendarSyncRunRecovery
{
    /** @return array{queued: int, running: int} */
    public function recover(): array
    {
        $counts = ['queued' => 0, 'running' => 0];

        CalendarSyncRun::query()
            ->where('status', 'queued')
            ->where(fn ($query) => $query
                ->whereNull('heartbeat_at')
                ->orWhere('heartbeat_at', '<', now()->subMinutes(5)))
            ->where('queued_at', '<', now()->subMinutes(5))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (CalendarSyncRun $run) use (&$counts) {
                $claimed = CalendarSyncRun::query()
                    ->whereKey($run->id)
                    ->where('status', 'queued')
                    ->where(fn ($query) => $query
                        ->whereNull('heartbeat_at')
                        ->orWhere('heartbeat_at', '<', now()->subMinutes(5)))
                    ->update(['heartbeat_at' => now(), 'updated_at' => now()]);

                if ($claimed === 1) {
                    $this->dispatch($run);
                    $counts['queued']++;
                }
            });

        CalendarSyncRun::query()
            ->where('status', 'running')
            ->where(fn ($query) => $query
                ->where('lease_expires_at', '<', now())
                ->orWhere(fn ($legacy) => $legacy
                    ->whereNull('lease_expires_at')
                    ->where('updated_at', '<', now()->subMinutes(30))))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (CalendarSyncRun $run) use (&$counts) {
                $requeued = DB::transaction(function () use ($run) {
                    $locked = CalendarSyncRun::query()->lockForUpdate()->find($run->id);
                    if (
                        ! $locked
                        || $locked->status !== 'running'
                        || ($locked->lease_expires_at && $locked->lease_expires_at->isFuture())
                        || (! $locked->lease_expires_at && $locked->updated_at->isAfter(now()->subMinutes(30)))
                    ) {
                        return null;
                    }

                    $locked->forceFill([
                        'status' => 'queued',
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'heartbeat_at' => now(),
                        'error_code' => 'lease_expired',
                        'safe_message' => 'La ejecución anterior se interrumpió y fue recuperada automáticamente.',
                    ])->save();

                    return $locked;
                });

                if ($requeued) {
                    $this->dispatch($requeued);
                    $counts['running']++;
                }
            });

        return $counts;
    }

    private function dispatch(CalendarSyncRun $run): void
    {
        SyncGoogleCalendarConnection::dispatch(
            (int) $run->campaign_id,
            (int) $run->calendar_connection_id,
            (bool) $run->force_full,
            (int) $run->id,
        );
    }
}
