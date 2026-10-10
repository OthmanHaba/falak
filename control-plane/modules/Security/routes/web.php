<?php

use Falak\Security\Http\Controllers\SecurityController;
use Illuminate\Support\Facades\Route;

// Server page tab (/servers/{id}/security) owned by Security.
Route::middleware(['auth', 'org'])->group(function () {
    Route::get('servers/{server}/security', [SecurityController::class, 'show'])->name('security.server');
});

Route::middleware(['auth', 'org'])->prefix('security')->name('security.')->group(function () {
    Route::get('/', [SecurityController::class, 'index'])->name('index');
    Route::post('servers/{server}/audit', [SecurityController::class, 'audit'])->name('audit');
    Route::post('servers/{server}/fixes', [SecurityController::class, 'fix'])->name('fixes.store');
    Route::post('servers/{server}/fixes/safe', [SecurityController::class, 'fixAllSafe'])->name('fixes.safe');
    Route::post('servers/{server}/fixes/{fix}/undo', [SecurityController::class, 'undo'])->name('fixes.undo');
});
