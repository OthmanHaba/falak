<?php

use Illuminate\Support\Facades\Route;
use Kiln\Builds\Http\Controllers\Internal\ArtifactController;
use Kiln\Builds\Http\Controllers\Internal\BuildEventsController;
use Kiln\Builds\Http\Controllers\Internal\BuildHeartbeatController;
use Kiln\Builds\Http\Controllers\Internal\NextBuildController;
use Kiln\Builds\Http\Middleware\AuthenticateBuilder;

// Internal API for kiln-builder (agent/internal/builder/endpoints.go). Mounted under /api.
Route::prefix('internal')->group(function () {
    Route::middleware(AuthenticateBuilder::class)->group(function () {
        Route::get('builds/next', NextBuildController::class)->name('builds.internal.next');
        Route::post('builds/{build}/events', BuildEventsController::class)->name('builds.internal.events');
        Route::post('builds/{build}/heartbeat', BuildHeartbeatController::class)->name('builds.internal.heartbeat');
    });

    // Local artifact driver: authorized by the signed, expiring URL itself.
    Route::put('artifacts/{key}', [ArtifactController::class, 'upload'])->where('key', '.*')->name('builds.artifacts.upload');
    Route::get('artifacts/{key}', [ArtifactController::class, 'download'])->where('key', '.*')->name('builds.artifacts.download');
});
