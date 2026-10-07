<?php

use Falak\Volumes\Http\Controllers\VolumeBackupController;
use Falak\Volumes\Http\Controllers\VolumeController;
use Falak\Volumes\Http\Controllers\VolumePageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    $ulid = '[0-9A-Za-z]{26}';

    // Pages: a server's volumes (a server tab), a project's, one volume.
    Route::get('servers/{server}/volumes', [VolumePageController::class, 'server'])->where('server', $ulid)->name('volumes.server');
    Route::post('servers/{server}/volumes/refresh', [VolumePageController::class, 'refresh'])->where('server', $ulid)->middleware('throttle:10,1')->name('volumes.refresh');
    Route::get('projects/{project}/volumes', [VolumePageController::class, 'project'])->where('project', $ulid)->name('volumes.project');
    Route::get('sites/{site}/volumes', [VolumePageController::class, 'site'])->where('site', $ulid)->name('volumes.site');

    Route::prefix('volumes')->name('volumes.')->group(function () use ($ulid) {
        Route::post('/', [VolumeController::class, 'store'])->name('store');

        Route::get('{volume}', [VolumePageController::class, 'show'])->where('volume', $ulid)->name('show');
        Route::patch('{volume}', [VolumeController::class, 'update'])->where('volume', $ulid)->name('update');
        Route::delete('{volume}', [VolumeController::class, 'destroy'])->where('volume', $ulid)->name('destroy');
        Route::post('{volume}/attachments', [VolumeController::class, 'attach'])->where('volume', $ulid)->name('attach');
        Route::post('{volume}/resize', [VolumeController::class, 'resize'])->where('volume', $ulid)->name('resize');
        Route::post('{volume}/clone', [VolumeController::class, 'clone'])->where('volume', $ulid)->name('clone');
        Route::post('{volume}/move', [VolumeController::class, 'move'])->where('volume', $ulid)->name('move');
        Route::get('{volume}/browse', [VolumeController::class, 'browse'])->where('volume', $ulid)->middleware('throttle:60,1')->name('browse');
        Route::post('{volume}/downloads', [VolumeController::class, 'download'])->where('volume', $ulid)->middleware('throttle:20,1')->name('downloads.store');
        Route::post('{volume}/backups', [VolumeBackupController::class, 'store'])->where('volume', $ulid)->name('backups.store');
        Route::post('{volume}/schedules', [VolumeBackupController::class, 'storeSchedule'])->where('volume', $ulid)->name('schedules.store');

        Route::delete('attachments/{attachment}', [VolumeController::class, 'detach'])->where('attachment', $ulid)->name('detach');
        Route::get('operations/{operation}', [VolumeController::class, 'operation'])->where('operation', $ulid)->name('operations.show');
        Route::get('operations/{operation}/file', [VolumeController::class, 'file'])->where('operation', $ulid)->middleware('throttle:30,1')->name('operations.file');
        Route::put('schedules/{schedule}', [VolumeBackupController::class, 'updateSchedule'])->where('schedule', $ulid)->name('schedules.update');
        Route::delete('schedules/{schedule}', [VolumeBackupController::class, 'destroySchedule'])->where('schedule', $ulid)->name('schedules.destroy');
        Route::post('backups/{backup}/restore', [VolumeBackupController::class, 'restore'])->where('backup', $ulid)->name('backups.restore');
        Route::post('backups/{backup}/key', [VolumeBackupController::class, 'exportKey'])->where('backup', $ulid)->middleware(['reauthenticated', 'throttle:10,1'])->name('backups.key');
        Route::post('schedules/{schedule}/drill', [VolumeBackupController::class, 'drill'])->where('schedule', $ulid)->middleware('throttle:10,1')->name('schedules.drill');
        Route::delete('backups/{backup}', [VolumeBackupController::class, 'destroy'])->where('backup', $ulid)->name('backups.destroy');
    });
});
