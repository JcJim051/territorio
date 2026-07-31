<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarWatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenewGoogleCalendarWatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly int $connectionId)
    {
    }

    public function handle(GoogleCalendarWatch $watch): void
    {
        $connection = CalendarConnection::find($this->connectionId);
        if ($connection?->isReady()) {
            $watch->renew($connection);
        }
    }
}
