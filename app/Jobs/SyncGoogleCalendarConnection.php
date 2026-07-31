<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SyncGoogleCalendarConnection implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public array $backoff = [30, 60, 180, 600, 1800];

    public function __construct(public readonly int $connectionId, public readonly bool $forceFull = false)
    {
        $this->afterCommit();
    }

    public function handle(GoogleCalendarSync $sync): void
    {
        $connection = CalendarConnection::find($this->connectionId);
        if (! $connection?->isReady()) {
            return;
        }

        $lock = Cache::lock('google-calendar:sync:'.$connection->id, 110);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $sync->sync($connection, $this->forceFull);
        } finally {
            $lock->release();
        }
    }
}
