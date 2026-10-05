<?php

use Illuminate\Support\Facades\Route;
use Falak\Functions\Http\Controllers\Api\FunctionApiController;

// Mounted under /api; used by `falak fn …`.
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('functions', [FunctionApiController::class, 'index'])->name('functions.index');
    Route::get('functions/{site}', [FunctionApiController::class, 'show'])->name('functions.show');
    Route::post('functions/{site}/deploy', [FunctionApiController::class, 'deploy'])->middleware('throttle:30,1')->name('functions.deploy');
    Route::get('functions/{site}/versions', [FunctionApiController::class, 'versions'])->name('functions.versions');
    Route::get('functions/{site}/versions/{number}', [FunctionApiController::class, 'version'])->whereNumber('number')->name('functions.version');
    Route::post('functions/{site}/versions/{number}/deploy', [FunctionApiController::class, 'deployVersion'])->whereNumber('number')->middleware('throttle:30,1')->name('functions.version.deploy');
    Route::post('functions/{site}/schedules/{schedule}/run', [FunctionApiController::class, 'run'])->middleware('throttle:20,1')->name('functions.schedules.run');
    Route::get('functions/{site}/runs/{run}', [FunctionApiController::class, 'runStatus'])->where('run', '[0-9A-Za-z]{26}')->name('functions.runs.show');
});
