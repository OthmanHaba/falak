<?php

use Illuminate\Support\Facades\Route;
use Kiln\Telemetry\Http\Controllers\Api\SiteAccessLogsApiController;
use Kiln\Telemetry\Http\Controllers\Api\SiteLogsApiController;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('sites/{site}/logs', SiteLogsApiController::class)->middleware('throttle:120,1')->name('sites.logs');
    Route::get('sites/{site}/access-logs', SiteAccessLogsApiController::class)->middleware('throttle:120,1')->name('sites.access-logs');
});
