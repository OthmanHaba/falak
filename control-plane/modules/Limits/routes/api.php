<?php

use Falak\Limits\Http\Controllers\CapacityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('servers/{server}/capacity', [CapacityController::class, 'show'])->where('server', '[0-9A-Za-z]{26}')->name('servers.capacity');
});
