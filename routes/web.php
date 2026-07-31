<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\CampaignSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MeetingChangeRequestController;
use App\Http\Controllers\GoogleCalendarConnectionController;
use App\Http\Controllers\GoogleCalendarWebhookController;
use App\Http\Controllers\CalendarReviewController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PublicInvitationController;
use App\Http\Controllers\TerritorialGraphController;
use App\Http\Controllers\CampaignOperationalSettingsController;
use App\Http\Controllers\DriverRouteController;
use App\Http\Controllers\ReferralNodeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:8,1');
});

Route::prefix('public/v1')->middleware('throttle:30,1')->group(function () {
    Route::get('/invitations/{token}', [PublicInvitationController::class, 'show'])
        ->name('public.invitations.show');
    Route::post('/invitations/{token}/accept', [PublicInvitationController::class, 'accept'])
        ->name('public.invitations.accept');
});

Route::post('/webhooks/google-calendar/v1', GoogleCalendarWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.google-calendar');

Route::middleware(['auth', 'campaign'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/campaign/switch', CampaignSwitchController::class)->name('campaign.switch');
    Route::get('/campaign/settings/operations', [CampaignOperationalSettingsController::class, 'edit'])->name('campaign.settings.operations');
    Route::put('/campaign/settings/operations', [CampaignOperationalSettingsController::class, 'update'])->name('campaign.settings.operations.update');
    Route::get('/driver/routes', DriverRouteController::class)->name('driver.routes');
    Route::get('/territorial/network', TerritorialGraphController::class)->name('territorial.graph');
    Route::get('/territorial/nodes', [ReferralNodeController::class, 'index'])->name('territorial.nodes.index');
    Route::post('/territorial/nodes/{publicId}', [ReferralNodeController::class, 'promote'])->name('territorial.nodes.promote');
    Route::post('/territorial/nodes/{publicId}/rotate', [ReferralNodeController::class, 'rotate'])->name('territorial.nodes.rotate');
    Route::put('/territorial/nodes/{publicId}/tokens/{tokenId}', [ReferralNodeController::class, 'update'])->name('territorial.nodes.tokens.update');
    Route::delete('/territorial/nodes/{publicId}/tokens/{tokenId}', [ReferralNodeController::class, 'revoke'])->name('territorial.nodes.tokens.revoke');
    Route::delete('/territorial/nodes/{publicId}', [ReferralNodeController::class, 'demote'])->name('territorial.nodes.demote');
    Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
    Route::get('/people/{publicId}', [PeopleController::class, 'show'])->name('people.show');
    Route::post('/people', [PeopleController::class, 'store'])->name('people.store');
    Route::put('/people/{publicId}', [PeopleController::class, 'update'])->name('people.update');
    Route::post('/people/{publicId}/verify', [PeopleController::class, 'verify'])->name('people.verify');
    Route::delete('/people/{publicId}', [PeopleController::class, 'destroy'])->name('people.destroy');

    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings.index');
    Route::post('/meetings', [MeetingController::class, 'store'])->name('meetings.store');
    Route::put('/meetings/{publicId}', [MeetingController::class, 'update'])->name('meetings.update');
    Route::post('/meetings/{publicId}/approve', [MeetingController::class, 'approve'])->name('meetings.approve');
    Route::post('/meetings/{publicId}/reject', [MeetingController::class, 'reject'])->name('meetings.reject');
    Route::delete('/meetings/{publicId}', [MeetingController::class, 'destroy'])->name('meetings.destroy');
    Route::post('/meeting-changes/{publicId}/approve', [MeetingChangeRequestController::class, 'approve'])->name('meeting-changes.approve');
    Route::post('/meeting-changes/{publicId}/reject', [MeetingChangeRequestController::class, 'reject'])->name('meeting-changes.reject');

    Route::prefix('calendar')->name('calendar.')->group(function () {
        Route::get('/settings', [GoogleCalendarConnectionController::class, 'index'])->name('settings');
        Route::put('/settings/service', [GoogleCalendarConnectionController::class, 'configure'])->name('settings.service');
        Route::get('/oauth/redirect', [GoogleCalendarConnectionController::class, 'redirect'])->name('oauth.redirect');
        Route::get('/oauth/callback', [GoogleCalendarConnectionController::class, 'callback'])->name('oauth.callback');
        Route::post('/select', [GoogleCalendarConnectionController::class, 'select'])->name('select');
        Route::post('/sync', [GoogleCalendarConnectionController::class, 'sync'])->name('sync');
        Route::delete('/disconnect', [GoogleCalendarConnectionController::class, 'disconnect'])->name('disconnect');
        Route::get('/reviews', [CalendarReviewController::class, 'index'])->name('reviews.index');
        Route::post('/reviews/{publicId}/approve', [CalendarReviewController::class, 'approve'])->name('reviews.approve');
        Route::post('/reviews/{publicId}/reject', [CalendarReviewController::class, 'reject'])->name('reviews.reject');
    });
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
    Route::put('/inventory/{resourceId}', [InventoryController::class, 'update'])->name('inventory.update');
    Route::post('/inventory/{resourceId}/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::delete('/inventory/{resourceId}', [InventoryController::class, 'destroy'])->name('inventory.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::put('/campaigns/{campaignId}', [CampaignController::class, 'update'])->name('campaigns.update');
        Route::delete('/campaigns/{campaignId}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{membershipId}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{membershipId}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{roleId}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{roleId}', [RoleController::class, 'destroy'])->name('roles.destroy');

        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');
    });

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
