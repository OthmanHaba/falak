<?php

use Falak\Processes\Http\Controllers\DaemonController;
use Falak\Processes\Http\Controllers\OctaneController;
use Falak\Processes\Http\Controllers\ProcessesController;
use Falak\Processes\Http\Controllers\ProcessStatusController;
use Falak\Processes\Http\Controllers\QueueController;
use Falak\Processes\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->prefix('sites/{site}')->name('processes.')->group(function () {
    Route::get('queues', [QueueController::class, 'index'])->name('queues.index');
    Route::post('queues', [QueueController::class, 'store'])->name('queues.store');
    Route::put('queues/{worker}', [QueueController::class, 'update'])->name('queues.update');
    Route::delete('queues/{worker}', [QueueController::class, 'destroy'])->name('queues.destroy');

    Route::get('daemons', [DaemonController::class, 'index'])->name('daemons.index');
    Route::post('daemons', [DaemonController::class, 'store'])->name('daemons.store');
    Route::put('daemons/{daemon}', [DaemonController::class, 'update'])->name('daemons.update');
    Route::delete('daemons/{daemon}', [DaemonController::class, 'destroy'])->name('daemons.destroy');

    Route::get('scheduler', [ScheduleController::class, 'index'])->name('scheduler.index');
    Route::post('scheduler', [ScheduleController::class, 'store'])->name('scheduler.store');
    Route::put('scheduler/{schedule}', [ScheduleController::class, 'update'])->name('scheduler.update');
    Route::delete('scheduler/{schedule}', [ScheduleController::class, 'destroy'])->name('scheduler.destroy');

    Route::get('processes', [ProcessesController::class, 'index'])->name('index');
    Route::post('processes/status', [ProcessStatusController::class, 'refresh'])->middleware('throttle:30,1')->name('status.refresh');
    Route::get('processes/status', [ProcessStatusController::class, 'show'])->name('status.show');
    Route::get('processes/octane', [OctaneController::class, 'show'])->name('octane.show');
    Route::post('processes/restart', [ProcessStatusController::class, 'restart'])->middleware('throttle:20,1')->name('restart');
});
