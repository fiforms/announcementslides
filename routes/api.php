<?php

use App\Http\Controllers\Api\SlideAnnouncerHeartbeatController;
use App\Http\Controllers\Api\SlideAnnouncerPairingController;
use App\Http\Controllers\Api\SlideAnnouncerSyncController;
use App\Http\Controllers\WidgetDataController;
use Illuminate\Support\Facades\Route;

Route::post('/slide-announcers/pair', [SlideAnnouncerPairingController::class, 'store'])
    ->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'slide-announcer.auth'])->group(function () {
    Route::post('/slide-announcers/heartbeat', [SlideAnnouncerHeartbeatController::class, 'store']);
    Route::get('/slide-announcers/slides', [SlideAnnouncerSyncController::class, 'index']);
    Route::get('/slide-announcers/shows', [SlideAnnouncerSyncController::class, 'shows']);
    // Overlay widget data, fetched server-side for the device (never a
    // URL from the device) — see WidgetDataController::device(). No
    // `throttle` here: it keys on getAuthIdentifier(), which a device
    // isn't; WidgetDataService rate-limits upstream fetches per device.
    Route::get('/slide-announcers/widget-data/{slideMedia}/{element}/{endpoint}', [WidgetDataController::class, 'device']);
});
