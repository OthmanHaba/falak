<?php

use Falak\Security\Http\Controllers\Api\SecurityApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('servers/{server}/security', [SecurityApiController::class, 'show'])->name('servers.security.show');
    Route::post('servers/{server}/security/audit', [SecurityApiController::class, 'audit'])->middleware('throttle:10,1')->name('servers.security.audit');
    Route::post('servers/{server}/security/fixes', [SecurityApiController::class, 'fix'])->middleware('throttle:30,1')->name('servers.security.fixes');
});
