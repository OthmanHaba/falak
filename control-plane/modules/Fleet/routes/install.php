<?php

use Falak\Fleet\Http\Controllers\InstallController;
use Illuminate\Support\Facades\Route;

// Stateless public installer endpoints (no session / CSRF).

Route::middleware('throttle:60,1')->group(function () {
    Route::get('install/agent/linux-{arch}', [InstallController::class, 'binary'])->whereIn('arch', ['amd64', 'arm64'])->name('fleet.install.binary');
    Route::get('install/{token}', [InstallController::class, 'script'])->where('token', '[A-Za-z0-9]{20,128}')->name('fleet.install.script');
});
