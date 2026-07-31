<?php

use App\Http\Controllers\Api\DivipolLookupController;
use App\Http\Controllers\Api\TestiappIntegrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/divipol')->middleware('throttle:60,1')->group(function () {
    Route::get('/places/{place}/tables', DivipolLookupController::class);
});

Route::prefix('integrations/testiapp/v1')
    ->middleware(['auth:sanctum', 'abilities:testiapp:sync'])
    ->group(function () {
        Route::post('/events', TestiappIntegrationController::class);
    });
