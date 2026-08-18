<?php

use App\Http\Controllers\Api\SlideAnnouncerHeartbeatController;
use App\Http\Controllers\Api\SlideAnnouncerPairingController;
use App\Http\Controllers\Api\SlideAnnouncerSyncController;
use Illuminate\Support\Facades\Route;

Route::post('/slide-announcers/pair', [SlideAnnouncerPairingController::class, 'store'])
    ->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'slide-announcer.auth'])->group(function () {
    Route::post('/slide-announcers/heartbeat', [SlideAnnouncerHeartbeatController::class, 'store']);
    Route::get('/slide-announcers/slides', [SlideAnnouncerSyncController::class, 'index']);
    Route::get('/slide-announcers/shows', [SlideAnnouncerSyncController::class, 'shows']);
});
