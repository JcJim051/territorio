<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\ProcessCalendarOutbox;
use App\Jobs\RenewGoogleCalendarWatch;
use App\Jobs\SyncGoogleCalendarConnection;
use App\Models\CalendarConnection;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ProcessCalendarOutbox)->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    CalendarConnection::where('status', 'active')->pluck('id')
        ->each(fn (int $id) => SyncGoogleCalendarConnection::dispatch($id));
})->everyTwoMinutes()->name('google-calendar-poll')->withoutOverlapping();

Schedule::call(function () {
    CalendarConnection::where('status', 'active')
        ->where(fn ($query) => $query->whereNull('watch_expires_at')->orWhere('watch_expires_at', '<', now()->addDay()))
        ->pluck('id')
        ->each(fn (int $id) => RenewGoogleCalendarWatch::dispatch($id));
})->hourly()->name('google-calendar-watch-renewal')->withoutOverlapping();

Schedule::call(function () {
    CalendarConnection::where('status', 'active')->pluck('id')
        ->each(fn (int $id) => SyncGoogleCalendarConnection::dispatch($id, true));
})->weekly()->name('google-calendar-window-reconciliation')->withoutOverlapping();
