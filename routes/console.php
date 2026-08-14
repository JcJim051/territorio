<?php

use App\Jobs\ProcessCalendarOutbox;
use App\Jobs\RenewGoogleCalendarWatch;
use App\Models\CalendarConnection;
use App\Services\CalendarSyncDispatcher;
use App\Services\CalendarSyncRunRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ProcessCalendarOutbox)->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    CalendarConnection::where('status', 'active')->get(['campaign_id', 'id'])
        ->each(fn (CalendarConnection $connection) => app(CalendarSyncDispatcher::class)
            ->dispatch($connection, 'polling'));
})->everyTwoMinutes()->name('google-calendar-poll')->withoutOverlapping();

Schedule::call(function () {
    CalendarConnection::where('status', 'active')
        ->where(fn ($query) => $query->whereNull('watch_expires_at')->orWhere('watch_expires_at', '<', now()->addDay()))
        ->get(['campaign_id', 'id'])
        ->each(fn (CalendarConnection $connection) => RenewGoogleCalendarWatch::dispatch(
            $connection->campaign_id,
            $connection->id,
        ));
})->hourly()->name('google-calendar-watch-renewal')->withoutOverlapping();

Schedule::call(function () {
    CalendarConnection::where('status', 'active')->get(['campaign_id', 'id'])
        ->each(fn (CalendarConnection $connection) => app(CalendarSyncDispatcher::class)
            ->dispatch($connection, 'reconciliation', forceFull: true));
})->weekly()->name('google-calendar-window-reconciliation')->withoutOverlapping();

Schedule::call(fn () => app(CalendarSyncRunRecovery::class)->recover())
    ->everyMinute()
    ->name('google-calendar-sync-run-recovery')
    ->withoutOverlapping();
