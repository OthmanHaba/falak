<?php

use Illuminate\Support\Facades\Route;
use Kiln\Databases\Http\Controllers\BackupController;
use Kiln\Databases\Http\Controllers\BackupScheduleController;
use Kiln\Databases\Http\Controllers\DatabaseController;
use Kiln\Databases\Http\Controllers\DatabaseServerController;
use Kiln\Databases\Http\Controllers\DatabaseUserController;
use Kiln\Databases\Http\Controllers\StorageProviderController;
use Kiln\Kernel\Http\LegacyRedirect;

Route::middleware(['auth', 'org'])->group(function () {
    // Backup storage lives in the settings shell (docs/UI_DESIGN.md §3); the old URL redirects.
    Route::get('settings/storage', [StorageProviderController::class, 'index'])->name('databases.storage.index');
    Route::get('databases/storage', LegacyRedirect::to('/settings/storage'))->name('databases.storage.legacy');
});

Route::middleware(['auth', 'org'])->prefix('databases')->name('databases.')->group(function () {
    Route::get('/', [DatabaseServerController::class, 'index'])->name('index');

    Route::get('servers/{databaseServer}', [DatabaseServerController::class, 'show'])->name('servers.show');
    Route::put('servers/{databaseServer}', [DatabaseServerController::class, 'update'])->name('servers.update');
    Route::post('servers/{databaseServer}/databases', [DatabaseController::class, 'store'])->name('databases.store');
    Route::post('servers/{databaseServer}/users', [DatabaseUserController::class, 'store'])->name('users.store');
    Route::post('servers/{databaseServer}/schedules', [BackupScheduleController::class, 'store'])->name('schedules.store');

    Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
    Route::post('databases/{database}/backups', [DatabaseController::class, 'backup'])->name('databases.backup');

    Route::put('users/{databaseUser}', [DatabaseUserController::class, 'update'])->name('users.update');
    Route::post('users/{databaseUser}/password', [DatabaseUserController::class, 'password'])->name('users.password');
    Route::post('users/{databaseUser}/reveal', [DatabaseUserController::class, 'reveal'])->middleware('throttle:30,1')->name('users.reveal');
    Route::delete('users/{databaseUser}', [DatabaseUserController::class, 'destroy'])->name('users.destroy');

    Route::put('schedules/{backupSchedule}', [BackupScheduleController::class, 'update'])->name('schedules.update');
    Route::post('schedules/{backupSchedule}/run', [BackupScheduleController::class, 'run'])->name('schedules.run');
    Route::delete('schedules/{backupSchedule}', [BackupScheduleController::class, 'destroy'])->name('schedules.destroy');

    Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
    Route::post('backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
    Route::delete('backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');

    Route::post('storage', [StorageProviderController::class, 'store'])->name('storage.store');
    Route::put('storage/{storageProvider}', [StorageProviderController::class, 'update'])->name('storage.update');
    Route::post('storage/{storageProvider}/verify', [StorageProviderController::class, 'verify'])->name('storage.verify');
    Route::delete('storage/{storageProvider}', [StorageProviderController::class, 'destroy'])->name('storage.destroy');
});
