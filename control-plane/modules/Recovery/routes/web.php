<?php

use Falak\Recovery\Http\Controllers\DisasterRecoveryController;
use Falak\Recovery\Http\Controllers\ReadinessController;
use Falak\Recovery\Http\Controllers\ServerRecoveryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('settings/disaster-recovery', [DisasterRecoveryController::class, 'show'])->name('recovery.settings');
    Route::post('settings/disaster-recovery/dismiss', [DisasterRecoveryController::class, 'dismiss'])->name('recovery.dismiss');

    Route::get('recovery/readiness', [ReadinessController::class, 'index'])->name('recovery.readiness');
    Route::get('recovery/readiness/{project}', [ReadinessController::class, 'show'])->where('project', '[0-9A-Za-z]{26}')->name('recovery.readiness.project');

    Route::get('servers/{server}/recovery', [ServerRecoveryController::class, 'show'])->where('server', '[0-9A-Za-z]{26}')->name('recovery.server');
    Route::post('servers/{server}/recovery/plan', [ServerRecoveryController::class, 'plan'])->where('server', '[0-9A-Za-z]{26}')->name('recovery.server.plan');
    Route::post('servers/{server}/recovery', [ServerRecoveryController::class, 'store'])->where('server', '[0-9A-Za-z]{26}')->name('recovery.server.store');
    Route::get('recoveries/{recovery}', [ServerRecoveryController::class, 'progress'])->where('recovery', '[0-9A-Za-z]{26}')->name('recovery.progress');
    Route::post('recoveries/{recovery}/steps/{step}/retry', [ServerRecoveryController::class, 'retry'])->where('recovery', '[0-9A-Za-z]{26}')->name('recovery.retry');
});
