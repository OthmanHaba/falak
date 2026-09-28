<?php

use Illuminate\Support\Facades\Route;
use Kiln\Servers\Http\Controllers\Api\ServerApiController;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('servers', [ServerApiController::class, 'index'])->name('servers.index');
    Route::post('servers', [ServerApiController::class, 'store'])->name('servers.store');
    Route::get('servers/{server}', [ServerApiController::class, 'show'])->name('servers.show');
    Route::delete('servers/{server}', [ServerApiController::class, 'destroy'])->name('servers.destroy');
    Route::post('servers/{server}/agent/upgrade', [ServerApiController::class, 'upgradeAgent'])->middleware('throttle:30,1')->name('servers.agent.upgrade');
});
