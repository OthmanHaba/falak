<?php

use Illuminate\Support\Facades\Route;
use Falak\Terminal\Http\Controllers\RecordingController;
use Falak\Terminal\Http\Controllers\SessionController;
use Falak\Terminal\Http\Controllers\StreamController;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('terminal', [SessionController::class, 'index'])->name('terminal.index');
    Route::get('servers/{server}/terminal', [SessionController::class, 'server'])->name('terminal.server');
    Route::post('terminal/servers/{server}/sessions', [SessionController::class, 'store'])->name('terminal.sessions.store');
    Route::get('terminal/sessions/{session}', [SessionController::class, 'show'])->name('terminal.sessions.show');
    Route::patch('terminal/sessions/{session}/share', [SessionController::class, 'share'])->name('terminal.sessions.share');
    Route::delete('terminal/sessions/{session}', [SessionController::class, 'destroy'])->name('terminal.sessions.destroy');

    Route::get('terminal/sessions/{session}/frames', [StreamController::class, 'frames'])->name('terminal.sessions.frames');
    Route::post('terminal/sessions/{session}/resize', [StreamController::class, 'resize'])->name('terminal.sessions.resize');

    Route::get('terminal/sessions/{session}/recording', [RecordingController::class, 'show'])->name('terminal.sessions.recording');
    Route::get('terminal/sessions/{session}/recording.cast', [RecordingController::class, 'download'])->name('terminal.sessions.recording.cast');
});

// Keystroke hot path: its own rate limiter so typing never competes with the app-wide limits.
Route::middleware(['auth', 'org', 'throttle:terminal-input'])
    ->post('terminal/sessions/{session}/input', [StreamController::class, 'input'])
    ->name('terminal.sessions.input');
